import { Component, inject } from '@angular/core';
import { RouterLink } from '@angular/router';

import { BrandLogoComponent } from '@shared/components/brand-logo.component';
import { AuthService } from '@shared/services/auth.service';

/**
 * Подвал повторяет всё меню — шапку и «Ещё» — сгруппированным по смыслу.
 * Добавили раздел в меню — добавьте и сюда.
 */
@Component({
    selector: 'app-footer',
    imports: [RouterLink, BrandLogoComponent],
    template: `
    <footer class="foot">
      <div class="wrap inner">
        <div class="col brand">
          <fg-logo class="logo"/>
          <p>Рецепты, которые получаются с первого раза.</p>
          <a class="help" routerLink="/help">Как пользоваться сайтом →</a>
        </div>

        <nav class="col" aria-label="Рецепты">
          <span class="head">Рецепты</span>
          <a routerLink="/">Главная</a>
          <a routerLink="/recipes">Все рецепты</a>
          <a routerLink="/reels">Рилсы</a>
          <a routerLink="/collections">Подборки</a>
          <a routerLink="/rating">Топ кулинаров</a>
        </nav>

        <nav class="col" aria-label="Обучение">
          <span class="head">Обучение</span>
          <a routerLink="/academy">Академия</a>
          <a routerLink="/club">Закрытая академия</a>
          <a routerLink="/books">Книги</a>
          <a routerLink="/journal">Журнал</a>
          <a routerLink="/contests">Конкурсы</a>
        </nav>

        <nav class="col" aria-label="Магазин">
          <span class="head">Магазин</span>
          <a routerLink="/shop">Товары для кухни</a>
          <a routerLink="/cart">Корзина</a>
          <a routerLink="/orders">Мои заказы</a>
          <a routerLink="/wallet">Кошелёк</a>
        </nav>

        <nav class="col" aria-label="Аккаунт">
          <span class="head">Аккаунт</span>
          @if (auth.isAuthenticated()) {
            <a routerLink="/account">Личный кабинет</a>
          } @else {
            <a routerLink="/login">Войти</a>
            <a routerLink="/register">Регистрация</a>
          }
          <a routerLink="/cookbook">Рецептосохранялка</a>
          <a routerLink="/help">Помощь</a>
        </nav>
      </div>
      <div class="wrap bottom">© {{ year }} Food Guru</div>
    </footer>
  `,
    styles: [`
    .foot { margin-top: 48px; background: var(--panel); color: var(--panel-ink); }
    .inner { padding-block: 36px 24px; display: grid; grid-template-columns: 1.4fr repeat(4, 1fr); gap: 28px; }
    @media (max-width: 900px) { .inner { grid-template-columns: repeat(2, 1fr); } .brand { grid-column: 1 / -1; } }
    .col { min-width: 0; display: flex; flex-direction: column; gap: 8px; font-size: 14px; }
    .logo { height: 22px; color: var(--panel-ink); }
    .head { font-weight: 700; color: #fff; margin-bottom: 2px; }
    .col p { margin: 0; }
    .col a { padding: 2px 0; }
    .col a:hover { color: #fff; }
    .help { color: #E8C77A; font-weight: 600; }
    .bottom { padding-block: 14px 28px; border-top: 1px solid rgb(255 255 255 / .08); font-size: 13px; }
  `]
})
export class FooterComponent {
  readonly auth = inject(AuthService);
  readonly year = new Date().getFullYear();
}
