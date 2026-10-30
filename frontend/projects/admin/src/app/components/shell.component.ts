import { Component, OnInit, inject, signal } from '@angular/core';
import { BrandLogoComponent } from '@shared/components/brand-logo.component';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';

import { ApiService } from '@shared/services/api.service';
import { AuthService } from '@shared/services/auth.service';
import { SITE_URL } from '@shared/tokens';
import { ToastComponent } from './toast.component';

@Component({
    selector: 'adm-shell',
    imports: [RouterOutlet, RouterLink, RouterLinkActive, ToastComponent, BrandLogoComponent],
    template: `
    <div class="shell">
      <aside class="side" [class.open]="menu()">
        <div class="brand">
          <fg-logo class="logo"/>
          <span class="sub">Админ-панель</span>
        </div>

        <nav>
          <a routerLink="/" routerLinkActive="active" [routerLinkActiveOptions]="{ exact: true }">Дашборд</a>
          <a routerLink="/recipes" routerLinkActive="active">
            Рецепты
            @if (pending()) { <span class="badge">{{ pending() }}</span> }
          </a>
          <a routerLink="/contests" routerLinkActive="active">Конкурсы</a>
          <a routerLink="/academy" routerLinkActive="active">Академия</a>
          <a routerLink="/journal" routerLinkActive="active">Журнал</a>
          <a routerLink="/products" routerLinkActive="active">Магазин</a>
          @if (auth.atLeast('editor_lead')) {
            <a routerLink="/orders" routerLinkActive="active">Заказы</a>
          }
          <a routerLink="/books" routerLinkActive="active">Книги</a>
          <a routerLink="/club" routerLinkActive="active">Закрытая академия</a>
          @if (auth.atLeast('editor_lead')) {
            <a routerLink="/users" routerLinkActive="active">Пользователи</a>
            <a routerLink="/reports" routerLinkActive="active">Жалобы</a>
          }
          @if (auth.atLeast('admin')) {
            <a routerLink="/activity" routerLinkActive="active">Журнал действий</a>
          }
          <a [href]="siteUrl" target="_blank" rel="noopener">Открыть сайт ↗</a>
        </nav>

        <div class="me">
          <div class="ava">{{ initials() }}</div>
          <div class="who">
            <span class="name">{{ auth.user()?.name }}</span>
            <span class="role">{{ auth.user()?.role_label }}</span>
          </div>
          <button class="out" type="button" (click)="logout()" aria-label="Выйти">⎋</button>
        </div>
      </aside>

      <div class="work">
        <button class="burger" type="button" (click)="menu.set(!menu())" aria-label="Меню">☰</button>
        <router-outlet />
      </div>
    </div>

    <adm-toast />
  `,
    styles: [`
    .shell { display: flex; flex-wrap: wrap; min-height: 100vh; }
    .side {
      flex: 1 1 230px; min-width: 0; background: var(--panel); color: var(--panel-ink);
      padding: 20px 14px; display: flex; flex-direction: column; gap: 18px;
    }
    .brand { padding: 0 10px; display: flex; flex-direction: column; gap: 2px; }
    .logo { height: 22px; }
    .sub { font-size: 11px; letter-spacing: .12em; text-transform: uppercase; opacity: .7; }
    nav { display: flex; flex-direction: column; gap: 3px; }
    nav a {
      display: flex; align-items: center; justify-content: space-between; gap: 10px;
      min-height: 44px; padding: 0 14px; border-radius: 10px; color: var(--panel-ink); font-size: 15px;
    }
    nav a:hover { color: #fff; background: var(--panel-hi); }
    nav a.active { background: var(--panel-hi); color: #fff; font-weight: 700; }
    .badge { padding: 2px 9px; border-radius: 999px; background: var(--brick); color: #fff; font-size: 12px; font-weight: 700; }
    .me {
      margin-top: auto; display: flex; align-items: center; gap: 10px;
      padding: 12px; border-radius: 12px; background: var(--panel-hi);
    }
    .ava {
      width: 38px; height: 38px; border-radius: 999px; background: #4C4738;
      display: grid; place-items: center; font-size: 13px; font-weight: 700; color: #fff; flex: 0 0 auto;
    }
    .who { display: flex; flex-direction: column; min-width: 0; flex: 1 1 auto; }
    .name { font-size: 14px; font-weight: 600; color: #fff; }
    .role { font-size: 12px; opacity: .75; }
    .out { background: none; border: 0; color: var(--panel-ink); font-size: 18px; padding: 6px; }
    .work { flex: 999 1 560px; min-width: 0; padding: 24px clamp(16px, 3vw, 28px); }
    .burger { display: none; margin-bottom: 14px; border: 1px solid var(--line); background: var(--surface); border-radius: 10px; padding: 8px 12px; }

    @media (max-width: 820px) {
      .side { display: none; flex-basis: 100%; }
      .side.open { display: flex; }
      .burger { display: block; }
    }
  `]
})
export class ShellComponent implements OnInit {
  private api = inject(ApiService);
  private router = inject(Router);
  auth = inject(AuthService);
  siteUrl = inject(SITE_URL);

  readonly menu = signal(false);
  readonly pending = signal(0);

  ngOnInit(): void {
    this.api.adminStats().subscribe({
      next: (res) => this.pending.set(res.data.moderation),
      error: () => this.pending.set(0),
    });
  }

  initials(): string {
    const name = this.auth.user()?.name || '';
    return name.split(' ').map((p) => p.charAt(0).toUpperCase()).slice(0, 2).join('');
  }

  logout(): void {
    this.auth.logout().subscribe({
      next: () => this.router.navigate(['/login']),
      error: () => this.router.navigate(['/login']),
    });
  }
}
