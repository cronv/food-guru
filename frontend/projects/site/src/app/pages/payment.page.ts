import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';

import { PaymentInfo, rub } from '@shared/models/commerce.models';
import { AuthService } from '@shared/services/auth.service';
import { CommerceService } from '@shared/services/commerce.service';
import { errorText } from '@shared/utils';
import { BreadcrumbsComponent } from '../components/breadcrumbs.component';

/**
 * Страница оплаты пополнения. В тестовом режиме это сама «платёжная форма»:
 * кнопки «Оплатить» и «Отменить» вместо реального банка. Для настоящего
 * провайдера сюда возвращаются после оплаты — страница показывает итог.
 */
@Component({
    selector: 'app-payment',
    imports: [RouterLink, BreadcrumbsComponent],
    template: `
    <div class="wrap band narrow">
      <app-breadcrumbs [items]="[{ label: 'Главная', link: '/' }, { label: 'Личный кабинет', link: '/account' }, { label: 'Кошелёк', link: '/wallet' }, { label: 'Оплата' }]" />
      @if (payment(); as p) {
        <div class="card">
          <span class="k">Пополнение кошелька · {{ p.method_label }}</span>
          <span class="sum tnum">{{ rub(p.amount) }}</span>

          @switch (p.status) {
            @case ('pending') {
              @if (p.provider === 'test') {
                <p class="test">Тестовая оплата. Настоящие деньги не списываются — выберите, чем закончится платёж.</p>
                @if (error()) { <p class="err" role="alert">{{ error() }}</p> }
                <div class="row">
                  <button class="btn btn-green" type="button" [disabled]="busy()" (click)="complete('succeeded')">Оплатить</button>
                  <button class="btn btn-ghost" type="button" [disabled]="busy()" (click)="complete('canceled')">Отменить</button>
                </div>
              } @else {
                <p class="muted">Ждём подтверждения от платёжной системы…</p>
              }
            }
            @case ('succeeded') {
              <p class="ok" role="status">Готово! Деньги зачислены. На балансе {{ rub(p.balance ?? 0) }}.</p>
              <div class="row">
                @if (back()) { <a class="btn btn-green" [routerLink]="back()">Вернуться к покупке</a> }
                <a class="btn" [class.btn-ghost]="back()" [class.btn-green]="!back()" routerLink="/wallet">В кошелёк</a>
              </div>
            }
            @case ('canceled') {
              <p class="muted" role="status">Платёж отменён, деньги не списаны.</p>
              <a class="btn btn-ghost" routerLink="/wallet">Попробовать ещё раз</a>
            }
          }
        </div>
      } @else if (error()) {
        <p class="err">{{ error() }}</p>
        <a class="btn btn-ghost" routerLink="/wallet">В кошелёк</a>
      } @else {
        <div class="spinner"></div>
      }
    </div>
  `,
    styles: [`
    .narrow { max-width: 560px; }
    .card {
      display: flex; flex-direction: column; gap: 14px; padding: 26px; border-radius: 20px;
      background: var(--surface); border: 1px solid var(--line);
    }
    .k { font-size: 13px; color: var(--muted); }
    .sum { font-family: var(--display); font-size: 40px; }
    .test { margin: 0; padding: 10px 12px; border-radius: 10px; background: var(--green-soft); color: var(--green-ink); font-size: 14px; }
    .ok { margin: 0; color: var(--green-ink); font-weight: 600; }
    .err { color: var(--brick); }
  `]
})
export class PaymentPage implements OnInit {
  private commerce = inject(CommerceService);
  private route = inject(ActivatedRoute);
  private auth = inject(AuthService);
  readonly rub = rub;

  readonly payment = signal<PaymentInfo | null>(null);
  readonly busy = signal(false);
  readonly error = signal('');
  readonly back = signal<string | null>(null);

  ngOnInit(): void {
    const id = this.route.snapshot.paramMap.get('id')!;
    const back = this.route.snapshot.queryParamMap.get('back');
    // Возврат только на свои страницы: чужой адрес в ссылке — путь к фишингу.
    this.back.set(back && back.startsWith('/') && !back.startsWith('//') ? back : null);

    const load = () => this.commerce.payment(id).subscribe({
      next: (res) => this.payment.set(res.data),
      error: (err) => this.error.set(errorText(err, 'Платёж не найден.')),
    });
    if (this.auth.loaded()) load();
    else this.auth.restore().subscribe(load);
  }

  complete(result: 'succeeded' | 'canceled'): void {
    const p = this.payment();
    if (!p) return;
    this.busy.set(true);
    this.commerce.testComplete(p.id, result).subscribe({
      next: (res) => {
        this.busy.set(false);
        this.payment.set(res.data);
      },
      error: (err) => {
        this.busy.set(false);
        this.error.set(errorText(err));
      },
    });
  }
}
