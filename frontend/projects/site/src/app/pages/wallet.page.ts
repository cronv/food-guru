import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';

import { PaymentMethods, WalletTransaction, rub } from '@shared/models/commerce.models';
import { AuthService } from '@shared/services/auth.service';
import { CommerceService } from '@shared/services/commerce.service';
import { errorText, formatShortDate } from '@shared/utils';
import { BreadcrumbsComponent } from '../components/breadcrumbs.component';
import { SeoService } from '../services/seo.service';

const PRESETS = [500, 1000, 3000, 5000];

@Component({
    selector: 'app-wallet',
    imports: [FormsModule, RouterLink, BreadcrumbsComponent],
    template: `
    <div class="wrap band">
      <app-breadcrumbs [items]="[{ label: 'Главная', link: '/' }, { label: 'Личный кабинет', link: '/account' }, { label: 'Кошелёк' }]" />
      <h1>Кошелёк</h1>

      @if (!auth.loaded()) {
        <div class="state"><div class="spinner"></div></div>
      } @else if (!auth.isAuthenticated()) {
        <div class="state">
          <p class="muted">Войдите, чтобы пополнить кошелёк и покупать книги, товары и подписку.</p>
          <a class="btn btn-green" routerLink="/login">Войти</a>
        </div>
      } @else if (!auth.isVerified()) {
        <div class="note-warn">Подтвердите адрес почты — после этого кошелёк откроется.</div>
      } @else {
        <div class="top">
          <section class="balance" aria-live="polite">
            <span class="k">Баланс</span>
            <span class="sum tnum">{{ rub(balance()) }}</span>
            <p class="muted small">Книги, товары и подписка оплачиваются с баланса. Вывести деньги нельзя — вернуть можно через поддержку.</p>
          </section>

          <form class="topup" (submit)="topUp($event)">
            <h2>Пополнить</h2>

            @if (methods(); as m) {
              @if (!m.available) {
                <p class="muted">Оплата на сайте скоро появится. Бесплатные книги, рецепты и конкурсы доступны уже сейчас.</p>
              } @else {
              @if (m.test_mode) {
                <p class="test">Тестовый режим: оплата не списывает настоящие деньги.</p>
              }

              <div class="presets" role="group" aria-label="Быстрый выбор суммы">
                @for (p of presets; track p) {
                  <button type="button" class="chipbtn" [class.on]="amount === p" (click)="amount = p">{{ p }} ₽</button>
                }
              </div>

              <label class="fl" for="amount">Сумма, ₽
                <input id="amount" name="amount" type="number" inputmode="numeric" [(ngModel)]="amount"
                       [min]="m.min / 100" [max]="m.max / 100" step="1">
                <span class="muted weak">От {{ rub(m.min) }} до {{ rub(m.max) }}</span>
              </label>

              <fieldset>
                <legend>Способ оплаты</legend>
                <div class="methods">
                  @for (pm of m.methods; track pm.code) {
                    <label class="method" [class.on]="method === pm.code">
                      <input type="radio" name="method" [value]="pm.code" [(ngModel)]="method">
                      <span>{{ pm.label }}</span>
                    </label>
                  }
                </div>
              </fieldset>

              @if (error()) { <p class="err" role="alert">{{ error() }}</p> }

              <button class="btn btn-green" type="submit" [disabled]="busy()">
                {{ busy() ? 'Переходим к оплате…' : 'Пополнить на ' + rub(amountKop()) }}
              </button>
              }
            } @else {
              <div class="spinner"></div>
            }
          </form>
        </div>

        <section class="history">
          <h2>История</h2>
          @if (history().length) {
            <ul>
              @for (t of history(); track t.id) {
                <li>
                  <span class="desc">{{ t.description }}<span class="muted when">{{ when(t.created_at) }}</span></span>
                  <span class="amt tnum" [class.plus]="t.amount > 0">{{ t.amount > 0 ? '+' : '−' }}{{ rub(abs(t.amount)) }}</span>
                </li>
              }
            </ul>
            @if (page() < lastPage()) {
              <button class="btn btn-ghost btn-sm" type="button" (click)="load(page() + 1)">Показать ещё</button>
            }
          } @else {
            <p class="muted">Операций пока не было.</p>
          }
        </section>
      }
    </div>
  `,
    styles: [`
    h1 { font-size: clamp(28px, 4vw, 38px); margin-bottom: 20px; }
    h2 { font-size: 22px; margin: 0; }
    .state { display: grid; place-items: center; gap: 12px; min-height: 200px; text-align: center; }
    .top { display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-start; }
    .balance {
      flex: 1 1 260px; padding: 22px; border-radius: 20px; background: var(--panel); color: var(--panel-ink);
      display: flex; flex-direction: column; gap: 6px;
    }
    .balance .k { font-size: 12px; letter-spacing: .1em; text-transform: uppercase; }
    .balance .sum { font-family: var(--display); font-size: 40px; color: #FFFBF4; }
    .balance .muted { color: var(--panel-ink); }
    .small { margin: 0; font-size: 13px; }
    .topup {
      flex: 2 1 340px; display: flex; flex-direction: column; gap: 14px; padding: 22px;
      border-radius: 20px; background: var(--surface); border: 1px solid var(--line);
    }
    .test { margin: 0; padding: 8px 12px; border-radius: 10px; background: var(--green-soft); color: var(--green-ink); font-size: 13px; }
    .presets { display: flex; flex-wrap: wrap; gap: 8px; }
    .chipbtn {
      min-height: 40px; padding: 0 14px; border-radius: 999px; border: 1px solid var(--line);
      background: var(--bg); font: inherit; font-weight: 600; color: var(--ink); cursor: pointer;
    }
    .chipbtn.on { border-color: var(--green); background: var(--green-soft); color: var(--green-ink); }
    fieldset { border: 0; margin: 0; padding: 0; }
    legend { font-weight: 600; margin-bottom: 8px; }
    .methods { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 8px; }
    .method {
      display: flex; align-items: center; gap: 10px; min-height: 48px; padding: 0 12px;
      border: 1px solid var(--line); border-radius: 12px; background: var(--bg); cursor: pointer; font-weight: 600;
    }
    .method.on { border-color: var(--green); background: var(--green-soft); }
    .weak { font-weight: 400; }
    .err { margin: 0; color: var(--brick); font-size: 14px; }
    .history { margin-top: 32px; display: flex; flex-direction: column; gap: 12px; max-width: 760px; }
    .history ul { list-style: none; margin: 0; padding: 0; }
    .history li { display: flex; justify-content: space-between; gap: 16px; padding: 12px 0; border-bottom: 1px solid var(--line); }
    .desc { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
    .when { font-size: 12px; }
    .amt { font-weight: 700; white-space: nowrap; }
    .amt.plus { color: var(--green-ink); }
  `]
})
export class WalletPage implements OnInit {
  private commerce = inject(CommerceService);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private seo = inject(SeoService);
  readonly auth = inject(AuthService);
  readonly rub = rub;
  readonly presets = PRESETS;

  readonly balance = signal(0);
  readonly history = signal<WalletTransaction[]>([]);
  readonly page = signal(1);
  readonly lastPage = signal(1);
  readonly methods = signal<PaymentMethods | null>(null);
  readonly busy = signal(false);
  readonly error = signal('');

  /** Сумма в рублях — так её вводит человек; на сервер уходят копейки. */
  amount = 1000;
  method = 'card';

  ngOnInit(): void {
    this.seo.apply({ title: 'Кошелёк', path: '/wallet', noindex: true });

    const preset = Number(this.route.snapshot.queryParamMap.get('amount'));
    if (preset > 0) this.amount = Math.ceil(preset);

    const start = () => {
      if (!this.auth.isVerified()) return;
      this.load(1);
      this.commerce.paymentMethods().subscribe((res) => {
        this.methods.set(res.data);
        this.method = res.data.methods[0]?.code ?? 'card';
      });
    };
    if (this.auth.loaded()) start();
    else this.auth.restore().subscribe(start);
  }

  amountKop(): number {
    return Math.round((Number(this.amount) || 0) * 100);
  }

  load(page: number): void {
    this.commerce.wallet(page).subscribe((res) => {
      this.balance.set(res.data.balance);
      this.history.set(page === 1 ? res.data.transactions : [...this.history(), ...res.data.transactions]);
      this.page.set(res.meta.current_page);
      this.lastPage.set(res.meta.last_page);
    });
  }

  topUp(event: Event): void {
    event.preventDefault();
    const m = this.methods();
    const kop = this.amountKop();
    if (!m) return;
    if (kop < m.min || kop > m.max) {
      this.error.set(`Сумма — от ${rub(m.min)} до ${rub(m.max)}.`);
      return;
    }

    this.busy.set(true);
    this.error.set('');
    this.commerce.topUp(kop, this.method).subscribe({
      next: (res) => {
        const back = this.route.snapshot.queryParamMap.get('back');
        const url = res.data.confirmation_url;
        if (!url) return;
        // Тестовая оплата — наша страница, настоящая — сайт платёжной системы.
        const local = url.match(/\/wallet\/pay\/[\w-]+$/);
        if (local) {
          this.router.navigate([local[0]], { queryParams: back ? { back } : {} });
        } else {
          location.href = url;
        }
      },
      error: (err) => {
        this.busy.set(false);
        this.error.set(errorText(err));
      },
    });
  }

  abs(n: number): number {
    return Math.abs(n);
  }

  when(iso: string | null): string {
    return formatShortDate(iso);
  }
}
