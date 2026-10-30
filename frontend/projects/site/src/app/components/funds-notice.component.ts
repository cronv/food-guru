import { Component, input } from '@angular/core';
import { RouterLink } from '@angular/router';

import { InsufficientFunds, rub } from '@shared/models/commerce.models';

/** «Не хватает N ₽ — пополнить». Ссылка сразу подставляет сумму в форму пополнения. */
@Component({
    selector: 'app-funds-notice',
    imports: [RouterLink],
    template: `
    @if (info(); as i) {
      <div class="box" role="alert">
        <p>На балансе {{ rub(i.balance) }} — не хватает <strong>{{ rub(i.shortfall) }}</strong>.</p>
        <a class="btn btn-green btn-sm" routerLink="/wallet" [queryParams]="{ amount: topUp(i.shortfall), back: back() }">
          Пополнить кошелёк
        </a>
      </div>
    }
  `,
    styles: [`
    .box {
      display: flex; flex-wrap: wrap; align-items: center; gap: 10px 16px; padding: 12px 14px;
      border-radius: 14px; background: var(--brick-soft); color: var(--ink);
    }
    p { margin: 0; flex: 1 1 220px; }
  `]
})
export class FundsNoticeComponent {
  readonly info = input<InsufficientFunds | null>(null);
  /** Куда вернуть после пополнения. */
  readonly back = input<string | null>(null);
  readonly rub = rub;

  /** Сумма пополнения в рублях, округлённая вверх до сотни и не меньше минимума. */
  topUp(shortfall: number): number {
    return Math.max(100, Math.ceil(shortfall / 10000) * 100);
  }
}
