import { Component, ElementRef, HostListener, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { NavigationEnd, Router, RouterLink, RouterLinkActive } from '@angular/router';
import { filter } from 'rxjs';

import { BrandLogoComponent } from '@shared/components/brand-logo.component';
import { AuthService } from '@shared/services/auth.service';
import { CartService } from '../services/cart.service';

@Component({
    selector: 'app-header',
    imports: [RouterLink, RouterLinkActive, BrandLogoComponent],
    template: `
    <header class="top">
      <div class="wrap top-in">
        <a class="logo" routerLink="/" aria-label="Food Guru, на главную"><fg-logo label=""/></a>

        <!-- «Главная» — это логотип; редкие разделы — в «Ещё», иначе меню не помещается до 960px. -->
        <nav class="nav">
          <a routerLink="/recipes" routerLinkActive="active">Рецепты</a>
          <a routerLink="/reels" routerLinkActive="active">Рилсы</a>
          <a routerLink="/collections" routerLinkActive="active">Подборки</a>
          <a routerLink="/academy" routerLinkActive="active">Академия</a>
          <a routerLink="/books" routerLinkActive="active">Книги</a>
          <a routerLink="/shop" routerLinkActive="active">Магазин</a>
          <div class="more">
            <button type="button" class="more-btn" [attr.aria-expanded]="more()" aria-controls="more-menu"
                    (click)="more.set(!more())">Ещё <span aria-hidden="true">▾</span></button>
            @if (more()) {
              <!-- Обычный список ссылок, не role="menu": то обещает навигацию стрелками,
                   которой здесь нет. Закрывается при переходе (NavigationEnd), кликом мимо и Esc. -->
              <div class="menu" id="more-menu">
                <a routerLink="/club" (click)="more.set(false)">Закрытая академия</a>
                <a routerLink="/contests" (click)="more.set(false)">Конкурсы</a>
                <a routerLink="/journal" (click)="more.set(false)">Журнал</a>
                <a routerLink="/rating" (click)="more.set(false)">Топ кулинаров</a>
                @if (auth.isAuthenticated()) {
                  <a routerLink="/cookbook" (click)="more.set(false)">Рецептосохранялка</a>
                  <a routerLink="/wallet" (click)="more.set(false)">Кошелёк</a>
                  <a routerLink="/orders" (click)="more.set(false)">Мои заказы</a>
                }
                <a routerLink="/help" (click)="more.set(false)">Помощь</a>
              </div>
            }
          </div>
        </nav>

        <div class="actions">
          <form class="search" (submit)="search($event)">
            <input type="search" name="q" placeholder="Найти рецепт" aria-label="Поиск по рецептам"
                   [value]="query()" (input)="query.set($any($event.target).value)">
          </form>

          <a class="cart" routerLink="/cart" [attr.aria-label]="'Корзина' + (cart.count() ? ', товаров: ' + cart.count() : '')">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
              <path d="M3 4h2l2.2 10.2a1.5 1.5 0 0 0 1.5 1.2h8.6a1.5 1.5 0 0 0 1.5-1.2L20.5 8H6"/>
              <circle cx="9.5" cy="19.5" r="1.3"/><circle cx="17" cy="19.5" r="1.3"/></svg>
            @if (cart.count()) { <span class="badge tnum">{{ cart.count() }}</span> }
          </a>

          @if (auth.isAuthenticated()) {
            <a class="btn btn-ghost btn-sm acct" routerLink="/account" [attr.title]="auth.user()?.name"
               [attr.aria-label]="'Личный кабинет: ' + auth.user()?.name">
              <span class="who">{{ auth.user()?.name }}</span>
              <svg class="ico" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/></svg>
            </a>
          } @else {
            <a class="btn btn-green btn-sm acct" routerLink="/login" aria-label="Войти">
              <span class="who">Войти</span>
              <svg class="ico" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/></svg>
            </a>
          }
        </div>

        <button class="burger" [attr.aria-expanded]="open()" aria-controls="drawer"
                aria-label="Меню" (click)="open.set(!open())">
          <span></span>
        </button>
      </div>

      <div class="drawer" id="drawer" [class.open]="open()">
        <div class="wrap drawer-in">
          <a routerLink="/" (click)="open.set(false)">Главная</a>
          <a routerLink="/books" (click)="open.set(false)">Книги</a>
          <a routerLink="/shop" (click)="open.set(false)">Магазин</a>
          <a routerLink="/club" (click)="open.set(false)">Закрытая академия</a>
          <a routerLink="/recipes" (click)="open.set(false)">Рецепты</a>
          <a routerLink="/reels" (click)="open.set(false)">Рилсы</a>
          <a routerLink="/collections" (click)="open.set(false)">Подборки</a>
          <a routerLink="/contests" (click)="open.set(false)">Конкурсы</a>
          <a routerLink="/academy" (click)="open.set(false)">Академия</a>
          <a routerLink="/journal" (click)="open.set(false)">Журнал</a>
          @if (auth.isAuthenticated()) {
            <a routerLink="/account" (click)="open.set(false)">Личный кабинет</a>
          } @else {
            <a routerLink="/register" (click)="open.set(false)">Войти</a>
          }
        </div>
      </div>
    </header>
  `,
    styles: [`
    .top {
      position: sticky;
      top: env(safe-area-inset-top, 0px);
      z-index: 15;
      background: var(--surface);
      border-bottom: 1px solid var(--line);
    }
    .top-in { display: flex; align-items: center; gap: 20px; padding-block: 12px; }
    .logo { flex: 0 0 auto; display: flex; align-items: center; min-height: 44px; color: var(--ink); }
    .logo fg-logo { height: 24px; }
    .nav { display: flex; gap: 18px; flex: 1 1 auto; min-width: 0; font-weight: 500; }
    .nav a { color: var(--muted); padding: 10px 0; white-space: nowrap; }
    .nav a.active { color: var(--green); font-weight: 700; }
    .actions { display: flex; align-items: center; gap: 10px; flex: 0 0 auto; margin-left: auto; }
    .search input { width: 190px; min-height: 40px; border-radius: 999px; font-size: 14px; }
    /* Длинное имя не должно толкать меню: кнопка ограничена, полное имя — в подсказке. */
    .who { display: block; max-width: 96px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

    .more { position: relative; }
    .more-btn {
      border: 0; background: none; padding: 10px 0; font: inherit; color: var(--muted);
      cursor: pointer; white-space: nowrap;
    }
    .more-btn[aria-expanded="true"] { color: var(--ink); }
    .menu {
      position: absolute; top: calc(100% + 6px); left: -12px; z-index: 30; min-width: 220px;
      display: flex; flex-direction: column; padding: 6px; border-radius: 14px;
      background: var(--bg); border: 1px solid var(--line); box-shadow: 0 12px 30px rgb(42 28 20 / .16);
    }
    .menu a { padding: 10px 12px; border-radius: 10px; color: var(--ink); font-weight: 500; }
    .menu a:hover, .menu a:focus-visible { background: var(--sunken); }

    .cart {
      position: relative; flex: 0 0 auto; width: 40px; height: 40px; border-radius: 999px;
      display: grid; place-items: center; color: var(--ink);
    }
    .cart:hover { background: var(--sunken); }
    .badge {
      position: absolute; top: 0; right: -2px; min-width: 18px; height: 18px; padding: 0 5px;
      border-radius: 999px; background: var(--brick); color: var(--on-accent);
      font-size: 11px; font-weight: 700; display: grid; place-items: center;
    }

    .burger {
      display: none;
      width: 44px; height: 44px;
      align-items: center; justify-content: center;
      border: 1px solid var(--line); border-radius: 12px;
      background: var(--surface); color: var(--ink);
    }
    .burger span { display: block; width: 18px; height: 2px; background: currentColor; position: relative; }
    .burger span::before, .burger span::after {
      content: ""; position: absolute; left: 0; width: 18px; height: 2px; background: currentColor;
      transition: transform .18s ease, top .18s ease;
    }
    .burger span::before { top: -6px; }
    .burger span::after { top: 6px; }
    .burger[aria-expanded="true"] span { background: transparent; }
    .burger[aria-expanded="true"] span::before { top: 0; transform: rotate(45deg); }
    .burger[aria-expanded="true"] span::after { top: 0; transform: rotate(-45deg); }

    .drawer { display: none; border-top: 1px solid var(--line); background: var(--surface); }
    .drawer-in { display: flex; flex-direction: column; gap: 4px; padding-block: 12px; }
    .drawer a {
      display: flex; align-items: center; min-height: 48px; padding: 0 14px;
      border-radius: 12px; font-size: 16px; font-weight: 600;
    }

    /*
     * Шестой пункт («Журнал») добавил к меню примерно 70px в обычном виде и 60px
     * в поджатом, поэтому оба порога подняты на столько же: раньше было 1040 и 860.
     * Запас считали по худшему случаю — вошедший читатель с длинным именем в кнопке,
     * ведь .nav умеет сжиматься, а сами ссылки стоят в nowrap и полезли бы на поиск.
     *
     * Седьмой пункт («Рилсы», ~55px) порог 960 не сдвигает: на нём же включается
     * нижняя панель. Место взято у поиска (140 → 110px) и у кнопки с именем,
     * которая теперь не шире 96px. Проверено на 965px с именем в 30 символов:
     * меню и поиск не сходятся.
     *
     * Книги, Магазин и корзина: «Главная» убрана (это логотип), Конкурсы и Журнал
     * ушли в «Ещё». Итог по ширине — примерно как раньше; проверять на 961–1145px.
     */
    @media (max-width: 1140px) {
      .top-in { gap: 14px; }
      .nav { gap: 12px; font-size: 14px; }
      .search input { width: 110px; }
    }

    @media (max-width: 960px) {
      .nav { display: none; }
      .burger { display: flex; }
      .drawer.open { display: block; }
    }

    /*
     * Ниже 960 разделы живут в нижней панели, поэтому бургер и выдвижное
     * меню убираются: два одинаковых меню на одном экране только путают.
     * Шапка остаётся ради логотипа, поиска и входа.
     */
    @media (max-width: 960px) {
      .burger, .drawer { display: none; }
      .top-in { gap: 10px; padding-block: 8px; }
      .search { flex: 1 1 auto; min-width: 0; }
      .search input { width: 100%; }
      /* Без сжатия блок держал ширину поля из десктопа и выталкивал страницу вбок. */
      .actions { gap: 6px; flex: 1 1 auto; min-width: 0; }
    }
    .acct .ico { display: none; }
    /*
     * Телефон: логотип, поиск, корзина и кнопка «Войти»/имя не помещались —
     * поле поиска сжималось до одной буквы. Кнопка аккаунта становится значком.
     */
    @media (max-width: 520px) {
      /* flex: none — иначе на 360px кнопку сжимало до 22px, мимо неё легко промахнуться. */
      .acct { flex: none; width: 40px; min-height: 40px; padding: 0; border-radius: 999px; justify-content: center; }
      .cart { flex: none; }
      .acct .who { display: none; }
      .acct .ico { display: block; }
      .logo fg-logo { height: 20px; }
    }
    @media (prefers-reduced-motion: reduce) {
      .burger span::before, .burger span::after { transition: none; }
    }
  `]
})
export class HeaderComponent {
  auth = inject(AuthService);
  readonly cart = inject(CartService);
  private router = inject(Router);
  private host = inject(ElementRef<HTMLElement>);

  readonly open = signal(false);
  readonly more = signal(false);
  readonly query = signal('');

  constructor() {
    // Меню «Ещё» закрывается при переходе на другую страницу.
    this.router.events.pipe(filter((e) => e instanceof NavigationEnd), takeUntilDestroyed())
      .subscribe(() => this.more.set(false));
  }

  /** Клик мимо меню или Esc — закрыть. */
  @HostListener('document:click', ['$event'])
  onDocumentClick(event: MouseEvent): void {
    if (this.more() && !this.host.nativeElement.querySelector('.more')?.contains(event.target as Node)) {
      this.more.set(false);
    }
  }

  @HostListener('document:keydown.escape')
  onEscape(): void {
    this.more.set(false);
  }

  search(event: Event): void {
    event.preventDefault();
    const q = this.query().trim();
    this.router.navigate(['/recipes'], { queryParams: q ? { q } : {} });
  }
}
