import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { RouterLink, RouterLinkActive } from '@angular/router';

import { AuthService } from '@shared/services/auth.service';
import { CartService } from '../services/cart.service';

/**
 * Нижняя панель разделов — только на узких экранах.
 *
 * На телефоне это главный способ перемещаться по сайту: большой палец
 * дотягивается до низа экрана, а не до шапки. Пять ярлыков — предел,
 * дальше подписи перестают читаться; всё остальное уходит в «Ещё».
 *
 * Панель прибита к низу и учитывает безопасную зону: на айфонах внизу
 * живёт системная полоса, и без отступа последний ярлык под неё попадает.
 * Отступ для содержимого страницы задан в styles.css — иначе подвал
 * оказывается под панелью.
 */
@Component({
    selector: 'app-bottom-nav',
    changeDetection: ChangeDetectionStrategy.OnPush,
    imports: [RouterLink, RouterLinkActive],
    template: `
    <nav class="bar" aria-label="Разделы сайта">
      <a routerLink="/" routerLinkActive="on" [routerLinkActiveOptions]="{ exact: true }">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M4 10.5 12 4l8 6.5V19a1 1 0 0 1-1 1h-4v-5H9v5H5a1 1 0 0 1-1-1Z"/>
        </svg>
        <span>Главная</span>
      </a>

      <a routerLink="/recipes" routerLinkActive="on">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M4 5.5A1.5 1.5 0 0 1 5.5 4H18a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5.5A1.5 1.5 0 0 1 4 18.5Z"/>
          <path d="M8 8h8M8 12h8M8 16h4"/>
        </svg>
        <span>Рецепты</span>
      </a>

      <a routerLink="/contests" routerLinkActive="on">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M7 4h10v5a5 5 0 0 1-10 0Z"/>
          <path d="M7 5H4v2a3 3 0 0 0 3 3M17 5h3v2a3 3 0 0 1-3 3"/>
          <path d="M12 14v3M9 20h6"/>
        </svg>
        <span>Конкурсы</span>
      </a>

      <a routerLink="/academy" routerLinkActive="on">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M12 4 3 8.5 12 13l9-4.5Z"/>
          <path d="M6.5 10.8V15c0 1.4 2.5 2.8 5.5 2.8s5.5-1.4 5.5-2.8v-4.2"/>
        </svg>
        <span>Академия</span>
      </a>

      <button type="button" class="more" [class.on]="open()"
              [attr.aria-expanded]="open()" aria-controls="more-sheet"
              (click)="open.set(!open())">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/>
        </svg>
        <span>Ещё</span>
      </button>
    </nav>

    @if (open()) {
      <!-- Затемнение закрывает панель по касанию мимо неё, как в приложениях. -->
      <div class="veil" (click)="open.set(false)" aria-hidden="true"></div>

      <div class="sheet" id="more-sheet" role="dialog" aria-label="Остальные разделы">
        <div class="grip" aria-hidden="true"></div>
        <div class="cols">
          <a routerLink="/books" (click)="open.set(false)">Книги</a>
          <a routerLink="/shop" (click)="open.set(false)">Магазин</a>
          <a routerLink="/club" (click)="open.set(false)">Закрытая академия</a>
          <a routerLink="/cart" (click)="open.set(false)">Корзина@if (cart.count()) { · {{ cart.count() }} }</a>
          <a routerLink="/reels" (click)="open.set(false)">Рилсы</a>
          <a routerLink="/collections" (click)="open.set(false)">Подборки</a>
          <a routerLink="/journal" (click)="open.set(false)">Журнал</a>
          <a routerLink="/rating" (click)="open.set(false)">Топ кулинаров</a>
          <a routerLink="/contests" (click)="open.set(false)">Конкурсы</a>
          <a routerLink="/help" (click)="open.set(false)">Помощь</a>
        </div>
        @if (auth.isAuthenticated()) {
          <a routerLink="/cookbook" (click)="open.set(false)">Рецептосохранялка</a>
          <a routerLink="/wallet" (click)="open.set(false)">Кошелёк</a>
          <a routerLink="/orders" (click)="open.set(false)">Мои заказы</a>
          <a routerLink="/account" (click)="open.set(false)">Личный кабинет</a>
        } @else {
          <a routerLink="/register" (click)="open.set(false)">Войти</a>
        }
      </div>
    }
  `,
    styles: [`
    :host { display: none; }

    .bar {
      position: fixed;
      left: 0; right: 0; bottom: 0;
      z-index: 20;
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      background: var(--bg);
      border-top: 1px solid var(--line);
      padding-bottom: env(safe-area-inset-bottom, 0px);
    }
    .bar a, .bar button {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 3px;
      min-height: 56px;
      padding: 6px 2px;
      border: none;
      background: none;
      color: var(--muted);
      font: inherit;
      font-size: 11px;
      font-weight: 600;
      cursor: pointer;
      /* Серый прямоугольник при касании выглядит как ошибка вёрстки. */
      -webkit-tap-highlight-color: transparent;
    }
    .bar svg {
      width: 24px; height: 24px;
      fill: none; stroke: currentColor;
      stroke-width: 1.75; stroke-linecap: round; stroke-linejoin: round;
    }
    .bar circle { fill: currentColor; stroke: none; }
    .bar .on { color: var(--brick); }
    .bar a:focus-visible, .bar button:focus-visible {
      outline: 3px solid var(--brick); outline-offset: -3px; border-radius: 8px;
    }

    .veil {
      position: fixed; inset: 0; z-index: 21;
      background: rgba(42, 28, 20, .45);
    }
    .sheet {
      position: fixed;
      left: 0; right: 0;
      bottom: calc(56px + env(safe-area-inset-bottom, 0px));
      z-index: 22;
      background: var(--bg);
      border-top: 1px solid var(--line);
      border-radius: 18px 18px 0 0;
      padding: 8px 14px 14px;
      display: flex;
      flex-direction: column;
      box-shadow: 0 -12px 32px rgba(42, 28, 20, .18);
    }
    .grip {
      width: 38px; height: 4px; border-radius: 999px;
      background: var(--line); margin: 2px auto 10px;
    }
    .sheet a {
      display: flex; align-items: center;
      min-height: 50px; padding: 0 10px;
      border-radius: 12px;
      font-size: 16px; font-weight: 600;
    }
    .sheet a:active { background: var(--sunken); }
    /* Пунктов стало много: разделы — в две колонки, чтобы лист не уходил под шапку. */
    .cols { display: grid; grid-template-columns: 1fr 1fr; column-gap: 8px; border-bottom: 1px solid var(--line); margin-bottom: 6px; padding-bottom: 6px; }
    .sheet { max-height: calc(100dvh - 140px); overflow-y: auto; }

    @media (max-width: 960px) {
      :host { display: block; }
    }
    @media (prefers-reduced-motion: no-preference) {
      .sheet { animation: rise .18s ease-out; }
      @keyframes rise { from { transform: translateY(12px); opacity: .6; } }
    }
  `]
})
export class BottomNavComponent {
  auth = inject(AuthService);
  readonly cart = inject(CartService);

  readonly open = signal(false);
}
