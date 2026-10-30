import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';

import { DeliveryMethod, InsufficientFunds, insufficientFunds, rub } from '@shared/models/commerce.models';
import { AuthService } from '@shared/services/auth.service';
import { CommerceService } from '@shared/services/commerce.service';
import { errorText } from '@shared/utils';
import { BreadcrumbsComponent } from '../components/breadcrumbs.component';
import { FundsNoticeComponent } from '../components/funds-notice.component';
import { CartService } from '../services/cart.service';
import { SeoService } from '../services/seo.service';
import { ToastService } from '../services/toast.service';

@Component({
    selector: 'app-cart',
    imports: [FormsModule, RouterLink, FundsNoticeComponent, BreadcrumbsComponent],
    template: `
    <div class="wrap band">
      <app-breadcrumbs [items]="[{ label: 'Главная', link: '/' }, { label: 'Магазин', link: '/shop' }, { label: 'Корзина' }]" />
      <h1>Корзина</h1>

      @if (!cart.lines().length) {
        <div class="state">
          <p class="muted">Корзина пуста.</p>
          <a class="btn btn-green" routerLink="/shop">В магазин</a>
          <a routerLink="/orders">Мои заказы</a>
        </div>
      } @else {
        <div class="layout">
          <ul class="lines">
            @for (l of cart.lines(); track l.product_id) {
              <li>
                <a class="thumb" [routerLink]="['/shop', l.slug]">@if (l.cover) { <img [src]="l.cover" alt=""> }</a>
                <div class="name">
                  <a [routerLink]="['/shop', l.slug]">{{ l.title }}</a>
                  <span class="muted tnum">{{ rub(l.price) }} за шт.</span>
                </div>
                <div class="qty" role="group" [attr.aria-label]="'Количество: ' + l.title">
                  <button type="button" class="btn btn-ghost" aria-label="Меньше" (click)="cart.setQuantity(l.product_id, l.quantity - 1)">−</button>
                  <span class="tnum">{{ l.quantity }}</span>
                  <button type="button" class="btn btn-ghost" aria-label="Больше" (click)="cart.setQuantity(l.product_id, l.quantity + 1)">+</button>
                </div>
                <span class="sum tnum">{{ rub(l.price * l.quantity) }}</span>
                <button type="button" class="remove" [attr.aria-label]="'Убрать ' + l.title" (click)="cart.remove(l.product_id)">✕</button>
              </li>
            }
          </ul>

          <form class="checkout" (submit)="submit($event)" novalidate>
            <h2>Оформление</h2>

            @if (!auth.isAuthenticated()) {
              <p class="muted">Чтобы оформить заказ, <a routerLink="/login">войдите</a> — корзина сохранится.</p>
            } @else {
              <fieldset>
                <legend>Доставка</legend>
                @for (d of delivery(); track d.code) {
                  <label class="opt" [class.on]="method === d.code">
                    <input type="radio" name="delivery" [value]="d.code" [(ngModel)]="method">
                    <span>{{ d.label }}</span>
                    <span class="muted tnum">{{ d.price ? rub(d.price) : 'бесплатно' }}</span>
                  </label>
                }
              </fieldset>

              <label class="fl" for="c-name">Получатель
                <input id="c-name" name="name" type="text" autocomplete="name" [(ngModel)]="name">
              </label>
              <label class="fl" for="c-phone">Телефон
                <input id="c-phone" name="phone" type="tel" autocomplete="tel" placeholder="+7 900 000-00-00" [(ngModel)]="phone">
              </label>
              @if (needsAddress()) {
                <label class="fl" for="c-city">Город
                  <input id="c-city" name="city" type="text" autocomplete="address-level2" [(ngModel)]="city">
                </label>
                <label class="fl" for="c-address">Адрес
                  <input id="c-address" name="address" type="text" autocomplete="street-address" placeholder="Улица, дом, квартира" [(ngModel)]="address">
                </label>
              }
              <label class="fl" for="c-comment">Комментарий
                <textarea id="c-comment" name="comment" rows="2" maxlength="1000" [(ngModel)]="comment"></textarea>
              </label>
            }

            <dl class="totals">
              <div><dt>Товары</dt><dd class="tnum">{{ rub(cart.total()) }}</dd></div>
              <div><dt>Доставка</dt><dd class="tnum">{{ rub(deliveryPrice()) }}</dd></div>
              <div class="all"><dt>Итого</dt><dd class="tnum">{{ rub(cart.total() + deliveryPrice()) }}</dd></div>
            </dl>

            @if (errors().length) {
              <ul class="err" role="alert">@for (e of errors(); track e) { <li>{{ e }}</li> }</ul>
            }
            <app-funds-notice [info]="funds()" back="/cart" />

            @if (auth.isAuthenticated()) {
              <button class="btn btn-green" type="submit" [disabled]="busy()">
                {{ busy() ? 'Оформляем…' : 'Оплатить с баланса' }}
              </button>
              <p class="muted small">Сумма спишется с кошелька. Пока заказ не начали собирать, его можно отменить — деньги вернутся на баланс.</p>
            }
          </form>
        </div>
      }
    </div>
  `,
    styles: [`
    h1 { font-size: clamp(28px, 4vw, 38px); margin-bottom: 18px; }
    .state { display: flex; flex-direction: column; align-items: flex-start; gap: 12px; }
    .layout { display: flex; flex-wrap: wrap; gap: 28px; align-items: flex-start; }
    .lines { flex: 2 1 420px; min-width: 0; list-style: none; margin: 0; padding: 0; }
    .lines li {
      display: grid; grid-template-columns: 64px 1fr auto auto 32px; align-items: center; gap: 12px;
      padding: 12px 0; border-bottom: 1px solid var(--line);
    }
    .thumb { width: 64px; height: 64px; border-radius: 10px; overflow: hidden; background: var(--sunken); }
    .thumb img { width: 100%; height: 100%; object-fit: cover; }
    .name { display: flex; flex-direction: column; min-width: 0; gap: 2px; }
    .name a { font-weight: 700; color: var(--ink); }
    .name .muted { font-size: 13px; }
    .qty { display: flex; align-items: center; gap: 6px; }
    .qty .btn { width: 36px; min-height: 36px; padding: 0; }
    .qty span { min-width: 22px; text-align: center; font-weight: 700; }
    .sum { font-weight: 700; white-space: nowrap; }
    .remove { width: 32px; height: 32px; border: 0; background: none; color: var(--muted); cursor: pointer; border-radius: 999px; }
    .remove:hover { background: var(--sunken); }
    @media (max-width: 560px) {
      .lines li { grid-template-columns: 56px 1fr 32px; grid-template-areas: 'img name x' 'img qty sum'; }
      .thumb { grid-area: img; width: 56px; height: 56px; }
      .name { grid-area: name; } .qty { grid-area: qty; } .sum { grid-area: sum; text-align: right; } .remove { grid-area: x; }
    }
    .checkout {
      flex: 1 1 320px; min-width: 0; display: flex; flex-direction: column; gap: 14px; padding: 20px;
      border-radius: 20px; background: var(--surface); border: 1px solid var(--line);
    }
    .checkout h2 { font-size: 22px; margin: 0; }
    fieldset { border: 0; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
    legend { font-weight: 600; margin-bottom: 6px; }
    .opt {
      display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: 10px; min-height: 46px;
      padding: 0 12px; border: 1px solid var(--line); border-radius: 12px; background: var(--bg); cursor: pointer;
    }
    .opt.on { border-color: var(--green); background: var(--green-soft); }
    .totals { margin: 0; display: flex; flex-direction: column; gap: 6px; }
    .totals div { display: flex; justify-content: space-between; }
    .totals dd { margin: 0; font-weight: 600; }
    .totals .all { padding-top: 8px; border-top: 1px solid var(--line); font-size: 18px; }
    .totals .all dd { font-weight: 800; }
    .err { margin: 0; padding-left: 18px; color: var(--brick); font-size: 14px; }
    .small { margin: 0; font-size: 12px; }
  `]
})
export class CartPage implements OnInit {
  private commerce = inject(CommerceService);
  private router = inject(Router);
  private seo = inject(SeoService);
  private toasts = inject(ToastService);
  readonly cart = inject(CartService);
  readonly auth = inject(AuthService);
  readonly rub = rub;

  readonly delivery = signal<DeliveryMethod[]>([]);
  readonly busy = signal(false);
  readonly errors = signal<string[]>([]);
  readonly funds = signal<InsufficientFunds | null>(null);
  private readonly methodSig = signal<DeliveryMethod['code']>('pickup');

  get method(): DeliveryMethod['code'] { return this.methodSig(); }
  set method(v: DeliveryMethod['code']) { this.methodSig.set(v); }

  readonly current = computed(() => this.delivery().find((d) => d.code === this.methodSig()));
  readonly deliveryPrice = computed(() => this.current()?.price ?? 0);
  readonly needsAddress = computed(() => this.current()?.needs_address ?? false);

  name = '';
  phone = '';
  city = '';
  address = '';
  comment = '';

  ngOnInit(): void {
    this.seo.apply({ title: 'Корзина', path: '/cart', noindex: true });
    this.commerce.delivery().subscribe((res) => this.delivery.set(res.data));
    const fill = () => { this.name ||= this.auth.user()?.name ?? ''; };
    if (this.auth.loaded()) fill(); else this.auth.restore().subscribe(fill);
  }

  submit(event: Event): void {
    event.preventDefault();
    this.funds.set(null);

    const problems: string[] = [];
    if (this.name.trim().length < 2) problems.push('Укажите, кто получит заказ.');
    if (!/^\+?[\d\s\-()]{10,20}$/.test(this.phone.trim())) problems.push('Укажите телефон для связи.');
    if (this.needsAddress() && !this.city.trim()) problems.push('Укажите город.');
    if (this.needsAddress() && !this.address.trim()) problems.push('Укажите адрес доставки.');
    this.errors.set(problems);
    if (problems.length) return;

    if (!this.auth.isVerified()) {
      this.toasts.show('Сначала подтвердите адрес почты');
      return;
    }

    this.busy.set(true);
    this.commerce.placeOrder({
      items: this.cart.lines().map((l) => ({ product_id: l.product_id, quantity: l.quantity })),
      delivery_method: this.method,
      recipient_name: this.name.trim(),
      phone: this.phone.trim(),
      city: this.needsAddress() ? this.city.trim() : undefined,
      address: this.needsAddress() ? this.address.trim() : undefined,
      comment: this.comment.trim() || undefined,
    }).subscribe({
      next: (res) => {
        this.busy.set(false);
        this.cart.clear();
        this.toasts.show(`Заказ ${res.data.number} оформлен`);
        this.router.navigate(['/orders'], { queryParams: { placed: res.data.number } });
      },
      error: (err) => {
        this.busy.set(false);
        const lack = insufficientFunds(err);
        if (lack) {
          this.funds.set(lack);
          return;
        }
        // Ошибки остатков приходят по позициям — показываем каждую.
        const fields = (err?.error?.errors ?? {}) as Record<string, string[]>;
        const list = Object.values(fields).flat();
        this.errors.set(list.length ? list : [errorText(err)]);
      },
    });
  }
}
