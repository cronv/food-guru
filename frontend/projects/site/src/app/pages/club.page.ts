import { Component, OnInit, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';

import { ClubIndex, ClubKind, InsufficientFunds, SubscriptionPlan, insufficientFunds, rub } from '@shared/models/commerce.models';
import { AuthService } from '@shared/services/auth.service';
import { CommerceService } from '@shared/services/commerce.service';
import { errorText, formatDate } from '@shared/utils';
import { FundsNoticeComponent } from '../components/funds-notice.component';
import { SeoService } from '../services/seo.service';
import { ToastService } from '../services/toast.service';

@Component({
    selector: 'app-club',
    imports: [RouterLink, FundsNoticeComponent],
    template: `
    <section class="hero">
      <div class="wrap band">
        <span class="eyebrow">Закрытая академия</span>
        <h1>Кухня ресторанного уровня — по подписке</h1>
        <p class="lead">Закрытые мастер-классы шефов, видеоуроки, ресторанные рецепты и платные книги из нашей библиотеки — всё открыто, пока действует подписка.</p>

        @if (index()?.meta?.subscription?.active) {
          <p class="active" role="status">
            Подписка активна до {{ date(index()!.meta.subscription.ends_at) }}. Продление добавится к концу текущего периода.
          </p>
        }

        @if (index(); as ix) {
          <div class="plans">
            @for (p of ix.meta.plans; track p.code) {
              <div class="plan" [class.best]="p.code === bestPlan(ix.meta.plans)">
                @if (p.code === bestPlan(ix.meta.plans)) { <span class="flag">Выгоднее всего</span> }
                <span class="t">{{ p.title }}</span>
                <span class="price tnum">{{ rub(p.price) }}</span>
                <span class="per tnum">{{ rub(p.per_month) }} в месяц</span>
                <button class="btn" [class.btn-green]="p.code === bestPlan(ix.meta.plans)" [class.btn-line]="p.code !== bestPlan(ix.meta.plans)"
                        type="button" [disabled]="busy()" (click)="subscribe(p)">
                  {{ ix.meta.subscription.active ? 'Продлить' : 'Оформить' }}
                </button>
              </div>
            }
          </div>
          <app-funds-notice [info]="funds()" back="/club" />
          <p class="pay-note">Оплата с баланса кошелька. Подписка не продлевается сама — списаний без вашего ведома не будет.</p>
        }
      </div>
    </section>

    <div class="wrap band">
      @if (index(); as ix) {
        <div class="filters" role="group" aria-label="Тип материалов">
          <button type="button" [class.on]="!kind()" (click)="setKind(null)">Всё</button>
          @for (k of ix.meta.kinds; track k.code) {
            <button type="button" [class.on]="kind() === k.code" (click)="setKind(k.code)">{{ k.label }}</button>
          }
        </div>

        <div class="grid items">
          @for (i of ix.data; track i.slug) {
            <a class="item" [routerLink]="['/club', i.slug]">
              <div class="shot">
                @if (i.cover) { <img [src]="i.cover.url" [alt]="''" loading="lazy"> }
                @if (!i.unlocked) {
                  <span class="lock" aria-label="Только по подписке">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                  </span>
                }
                @if (i.has_video) { <span class="play" aria-hidden="true">▶</span> }
              </div>
              <div class="tags">
                <span class="chip chip-g">{{ i.kind_label }}</span>
                @if (i.duration_minutes) { <span class="chip tnum">{{ i.duration_minutes }} мин</span> }
              </div>
              <h3>{{ i.title }}</h3>
              @if (i.chef_name) { <p class="muted small">{{ i.chef_name }}</p> }
            </a>
          }
        </div>

        @if (ix.meta.books.length && !kind()) {
          <section class="books">
            <div class="sec-head"><h2>Книги в подписке</h2><a class="btn btn-ghost btn-sm" routerLink="/books">Все книги →</a></div>
            <div class="shelf">
              @for (b of ix.meta.books; track b.slug) {
                <a class="book" [routerLink]="['/books', b.slug]">
                  <div class="cover">@if (b.cover) { <img [src]="b.cover.url" [alt]="b.cover.alt || b.title" loading="lazy"> }</div>
                  <span class="bt">{{ b.title }}</span>
                </a>
              }
            </div>
          </section>
        }
      } @else {
        <div class="spinner"></div>
      }
    </div>
  `,
    styles: [`
    .hero { background: var(--panel); color: var(--panel-ink); }
    .hero .band { display: flex; flex-direction: column; gap: 14px; }
    .eyebrow { font-size: 12px; letter-spacing: .14em; text-transform: uppercase; color: #E8C77A; font-weight: 700; }
    .hero h1 { color: #FFFBF4; font-size: clamp(30px, 4.6vw, 48px); line-height: 1.08; max-width: 18ch; }
    .lead { margin: 0; max-width: 62ch; font-size: 17px; }
    .active { margin: 0; padding: 10px 14px; border-radius: 12px; background: rgb(255 255 255 / .08); color: #FFFBF4; }
    .plans { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-top: 6px; }
    .plan {
      position: relative; display: flex; flex-direction: column; gap: 6px; padding: 18px;
      border-radius: 18px; background: var(--panel-hi); border: 1px solid rgb(255 255 255 / .08);
    }
    .plan.best { border-color: #E8C77A; }
    .flag { position: absolute; top: -10px; right: 14px; padding: 2px 10px; border-radius: 999px; background: #E8C77A; color: #2A1C14; font-size: 11px; font-weight: 800; }
    .t { font-weight: 700; color: #FFFBF4; }
    .price { font-family: var(--display); font-size: 30px; color: #FFFBF4; }
    .per { font-size: 13px; }
    .plan .btn { margin-top: 8px; }
    .plan .btn-line { color: #FFFBF4; border-color: rgb(255 255 255 / .35); }
    .pay-note { margin: 0; font-size: 13px; }
    .filters { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 20px; }
    .filters button {
      min-height: 40px; padding: 0 16px; border-radius: 999px; border: 1px solid var(--line);
      background: var(--surface); font: inherit; font-weight: 600; color: var(--ink); cursor: pointer;
    }
    .filters button.on { background: var(--green); border-color: var(--green); color: var(--on-accent); }
    .items { grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); }
    .item { display: flex; flex-direction: column; gap: 8px; padding: 12px; border-radius: 18px; background: var(--surface); border: 1px solid var(--line); color: var(--ink); }
    .shot { position: relative; aspect-ratio: 4 / 3; border-radius: 12px; overflow: hidden; background: var(--sunken); }
    .shot img { width: 100%; height: 100%; object-fit: cover; }
    .lock {
      position: absolute; top: 8px; right: 8px; width: 32px; height: 32px; border-radius: 999px;
      display: grid; place-items: center; background: rgb(0 0 0 / .6); color: #fff;
    }
    .play {
      position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); width: 52px; height: 52px;
      border-radius: 999px; display: grid; place-items: center; background: rgb(0 0 0 / .55); color: #fff; font-size: 18px;
    }
    .tags { display: flex; flex-wrap: wrap; gap: 6px; }
    h3 { margin: 0; font-size: 18px; line-height: 1.25; }
    .small { margin: 0; font-size: 13px; }
    .books { margin-top: 40px; }
    .shelf { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 18px; }
    .book { display: flex; flex-direction: column; gap: 8px; color: var(--ink); }
    .cover { aspect-ratio: 2 / 3; border-radius: 6px 12px 12px 6px; overflow: hidden; background: var(--sunken); box-shadow: 0 8px 20px rgb(42 28 20 / .15); }
    .cover img { width: 100%; height: 100%; object-fit: cover; }
    .bt { font-weight: 700; font-size: 14px; }
  `]
})
export class ClubPage implements OnInit {
  private commerce = inject(CommerceService);
  private seo = inject(SeoService);
  private toasts = inject(ToastService);
  private auth = inject(AuthService);
  readonly rub = rub;

  readonly index = signal<ClubIndex | null>(null);
  readonly kind = signal<ClubKind | null>(null);
  readonly busy = signal(false);
  readonly funds = signal<InsufficientFunds | null>(null);

  ngOnInit(): void {
    this.seo.apply({
      title: 'Закрытая академия',
      description: 'Закрытые мастер-классы, видеоуроки, ресторанные рецепты и платные книги — по подписке.',
      path: '/club',
    });
    this.load();
  }

  setKind(k: ClubKind | null): void {
    this.kind.set(k);
    this.load();
  }

  /** Самый выгодный — с наименьшей ценой месяца. */
  bestPlan(plans: SubscriptionPlan[]): string | null {
    return plans.length > 1 ? [...plans].sort((a, b) => a.per_month - b.per_month)[0].code : null;
  }

  subscribe(plan: SubscriptionPlan): void {
    if (!this.auth.isAuthenticated()) {
      this.toasts.show('Войдите, чтобы оформить подписку');
      return;
    }
    if (!this.auth.isVerified()) {
      this.toasts.show('Сначала подтвердите адрес почты');
      return;
    }

    this.busy.set(true);
    this.funds.set(null);
    this.commerce.subscribe(plan.code).subscribe({
      next: (res) => {
        this.busy.set(false);
        this.toasts.show(`Подписка активна до ${formatDate(res.data.ends_at)}`);
        this.load();
      },
      error: (err) => {
        this.busy.set(false);
        const lack = insufficientFunds(err);
        if (lack) this.funds.set(lack);
        else this.toasts.error(errorText(err));
      },
    });
  }

  date(iso: string | null): string {
    return formatDate(iso);
  }

  private load(): void {
    this.commerce.club(this.kind() ?? undefined).subscribe((res) => this.index.set(res));
  }
}
