import { Component, inject } from '@angular/core';
import { Router } from '@angular/router';

import { AuthService } from '@shared/services/auth.service';

@Component({
  selector: 'adm-denied',
  standalone: true,
  template: `
    <div class="screen">
      <div class="box">
        <h1>Нет доступа</h1>
        <p class="muted">
          Этот раздел доступен с более высокой ролью. Сейчас у вас
          «{{ auth.user()?.role_label || 'без роли' }}».
        </p>
        <div class="row">
          <button class="btn btn-green" type="button" (click)="home()">На дашборд</button>
          <button class="btn btn-ghost" type="button" (click)="logout()">Выйти</button>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .screen { min-height: 100vh; display: grid; place-items: center; padding: 20px; }
    .box {
      width: min(460px, 100%); background: var(--surface); border: 1px solid var(--line);
      border-radius: 20px; padding: 30px; display: flex; flex-direction: column; gap: 14px; text-align: center;
    }
    h1 { font-size: 26px; }
    p { margin: 0; }
    .row { justify-content: center; }
  `],
})
export class DeniedPage {
  auth = inject(AuthService);
  private router = inject(Router);

  home(): void {
    this.router.navigate(['/']);
  }

  logout(): void {
    this.auth.logout().subscribe({
      next: () => this.router.navigate(['/login']),
      error: () => this.router.navigate(['/login']),
    });
  }
}
