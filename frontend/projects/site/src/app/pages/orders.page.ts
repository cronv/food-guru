import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';

import { Order, rub } from '@shared/models/commerce.models';
import { AuthService } from '@shared/services/auth.service';
import { CommerceService } from '@shared/services/commerce.service';
import { errorText, formatShortDate } from '@shared/utils';
import { BreadcrumbsComponent } from '../components/breadcrumbs.component';
import { SeoService } from '../services/seo.service';
import { ToastService } from '../services/toast.service';

@Component({
    selector: 'app-orders',
    imports: [RouterLink, BreadcrumbsComponent],
    template: `
    <div class="wrap band narrow">
      <app-breadcrumbs [items]="[{ label: 'Главная', link: '/' }, { label: 'Личный кабинет', link: '/account' }, { label: 'Мои заказы' }]" />
      <h1>Мои заказы</h1>

      @if (!auth.loaded()) {
        <div class="spinner"></div>
      } @else if (!auth.isAuthenticated()) {
        <p class="muted">Войдите, чтобы увидеть заказы. <a routerLink="/login">Войти</a></p>
      } @else if (orders().length) {
        <ul class="list">
          @for (o of orders(); track o.number) {
            <li class="order" [class.fresh]="o.number === placed()">
              <div class="head">
                <strong class="tnum">№ {{ o.number }}</strong>
                <span class="status" [attr.data-s]="o.status">{{ o.status_label }}</span>
                <span class="muted when">{{ when(o.created_at) }}</span>
              </div>
              <ul class="items">
                @for (i of o.items; track $index) {
                  <li><span>{{ i.title }} × {{ i.quantity }}</span><span class="tnum">{{ rub(i.total) }}</span></li>
                }
                <li class="muted"><span>{{ o.delivery_label }}</span><span class="tnum">{{ rub(o.delivery_price) }}</span></li>
              </ul>
              <div class="foot">
                <span>Итого <strong class="tnum">{{ rub(o.total) }}</strong></span>
                @if (o.can_cancel) {
                  @if (confirm() === o.number) {
                    <span class="muted">Отменить? Деньги вернутся на баланс.</span>
                    <button class="btn btn-sm danger" type="button" [disabled]="busy()" (click)="cancel(o)">Да, отменить</button>
                    <button class="btn btn-ghost btn-sm" type="button" (click)="confirm.set(null)">Нет</button>
                  } @else {
                    <button class="btn btn-ghost btn-sm" type="button" (click)="confirm.set(o.number)">Отменить заказ</button>
                  }
                }
              </div>
            </li>
          }
        </ul>
      } @else if (!loading()) {
        <p class="muted">Заказов пока нет. <a routerLink="/shop">В магазин</a></p>
      }
    </div>
  `,
    styles: [`
    .narrow { max-width: 820px; }
    h1 { font-size: clamp(28px, 4vw, 38px); margin-bottom: 18px; }
    .list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 12px; }
    .order { padding: 16px 18px; border-radius: 16px; background: var(--surface); border: 1px solid var(--line); }
    .order.fresh { border-color: var(--green); box-shadow: 0 0 0 3px var(--green-soft); }
    .head { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
    .when { margin-left: auto; font-size: 13px; }
    .status { padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; background: var(--sunken); }
    .status[data-s="delivered"] { background: var(--green-soft); color: var(--green-ink); }
    .status[data-s="canceled"] { background: var(--brick-soft); color: var(--brick-ink); }
    .items { list-style: none; margin: 10px 0; padding: 0; font-size: 14px; }
    .items li { display: flex; justify-content: space-between; gap: 12px; padding: 4px 0; }
    .foot { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding-top: 10px; border-top: 1px solid var(--line); }
    .foot > span:first-child { margin-right: auto; }
    .danger { background: var(--brick); color: var(--on-accent); }
  `]
})
export class OrdersPage implements OnInit {
  private commerce = inject(CommerceService);
  private route = inject(ActivatedRoute);
  private seo = inject(SeoService);
  private toasts = inject(ToastService);
  readonly auth = inject(AuthService);
  readonly rub = rub;

  readonly orders = signal<Order[]>([]);
  readonly loading = signal(true);
  readonly busy = signal(false);
  readonly confirm = signal<string | null>(null);
  readonly placed = signal<string | null>(null);

  ngOnInit(): void {
    this.seo.apply({ title: 'Мои заказы', path: '/orders', noindex: true });
    this.placed.set(this.route.snapshot.queryParamMap.get('placed'));
    const load = () => {
      if (!this.auth.isAuthenticated()) return;
      this.commerce.myOrders().subscribe({
        next: (res) => {
          this.orders.set(res.data);
          this.loading.set(false);
        },
        error: () => this.loading.set(false),
      });
    };
    if (this.auth.loaded()) load(); else this.auth.restore().subscribe(load);
  }

  cancel(o: Order): void {
    this.busy.set(true);
    this.commerce.cancelOrder(o.number).subscribe({
      next: (res) => {
        this.busy.set(false);
        this.confirm.set(null);
        this.orders.update((list) => list.map((x) => (x.number === o.number ? res.data : x)));
        this.toasts.show('Заказ отменён, деньги вернулись на баланс');
      },
      error: (err) => {
        this.busy.set(false);
        this.toasts.error(errorText(err));
      },
    });
  }

  when(iso: string | null): string {
    return formatShortDate(iso);
  }
}
