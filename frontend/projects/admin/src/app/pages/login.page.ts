import { Component, OnInit, inject, signal } from '@angular/core';
import { BrandLogoComponent } from '@shared/components/brand-logo.component';
import { ActivatedRoute, Router } from '@angular/router';
import { FormsModule } from '@angular/forms';

import { PasswordLoginComponent } from '@shared/components/password-login.component';
import { User } from '@shared/models/api.models';
import { ApiService } from '@shared/services/api.service';
import { AuthService } from '@shared/services/auth.service';
import { errorText } from '@shared/utils';
import { ToastService } from '../services/toast.service';

/**
 * Вход в админку: по почте и паролю либо по ссылке из письма, как на сайте.
 * Если в адресе есть токен (/login?token=…), сразу меняем его на сессию.
 */
@Component({
    selector: 'adm-login',
    imports: [FormsModule, BrandLogoComponent, PasswordLoginComponent],
    template: `
    <div class="screen">
      <div class="box">
        <div class="brand">
          <fg-logo class="logo"/>
          <span class="sub">Админ-панель</span>
        </div>

        @if (stage() === 'sent') {
          <h1>Письмо отправлено</h1>
          <p class="muted">
            Ссылка для входа ушла на <strong>{{ email }}</strong>. Она живёт 15 минут
            и срабатывает один раз.
          </p>
          <button class="btn btn-ghost" type="button" (click)="stage.set('form')">Другой адрес</button>
        } @else if (stage() === 'checking') {
          <div class="spinner"></div>
          <p class="muted">Проверяем ссылку…</p>
        } @else {
          <h1>Вход</h1>

          <div class="tabs" role="tablist" aria-label="Способ входа">
            <button type="button" role="tab" [attr.aria-selected]="method() === 'password'"
                    [class.on]="method() === 'password'" (click)="method.set('password')">
              По паролю
            </button>
            <button type="button" role="tab" [attr.aria-selected]="method() === 'link'"
                    [class.on]="method() === 'link'" (click)="method.set('link')">
              По ссылке
            </button>
          </div>

          @if (method() === 'password') {
            <fg-password-login (loggedIn)="onPasswordLogin($event)" />
            <p class="muted small">
              Пароля ещё нет? Войдите по ссылке из письма и задайте его в личном кабинете на сайте.
            </p>
          } @else {
            <p class="muted">Введите рабочую почту — пришлём ссылку для входа.</p>
            <form (submit)="submit($event)">
              <label class="fl" for="mail">Email
                <input id="mail" type="email" name="mail" [(ngModel)]="email"
                       placeholder="manager@example.com" autocomplete="email" required>
              </label>
              <button class="btn btn-green full" type="submit" [disabled]="saving()">
                {{ saving() ? 'Отправляем…' : 'Получить ссылку' }}
              </button>
            </form>
          }

          <p class="muted small">
            Доступ в панель есть у контент-менеджеров, руководителей редакции и администраторов.
          </p>
        }
      </div>
    </div>
  `,
    styles: [`
    .screen { min-height: 100vh; display: grid; place-items: center; padding: 20px; }
    .box {
      width: min(420px, 100%); background: var(--surface); border: 1px solid var(--line);
      border-radius: 20px; padding: 30px; display: flex; flex-direction: column; gap: 14px;
    }
    .brand { display: flex; flex-direction: column; gap: 2px; margin-bottom: 6px; }
    .logo { height: 22px; }
    .sub { font-size: 11px; letter-spacing: .12em; text-transform: uppercase; color: var(--muted); }
    h1 { font-size: 26px; }
    p { margin: 0; }
    .tabs {
      display: grid; grid-template-columns: 1fr 1fr; gap: 4px; padding: 4px;
      background: var(--bg-soft, var(--line)); border-radius: 12px;
    }
    .tabs button {
      min-height: 44px; border: 0; border-radius: 9px; background: none;
      font: inherit; font-weight: 600; color: var(--muted); cursor: pointer;
    }
    .tabs button.on { background: var(--surface); color: var(--ink); box-shadow: 0 1px 3px rgb(0 0 0 / .12); }
    .small { font-size: 13px; }
    form { display: flex; flex-direction: column; gap: 14px; }
    .full { width: 100%; }
  `]
})
export class LoginPage implements OnInit {
  private api = inject(ApiService);
  private auth = inject(AuthService);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private toasts = inject(ToastService);

  email = '';
  readonly method = signal<'password' | 'link'>('password');
  readonly stage = signal<'form' | 'sent' | 'checking'>('form');
  readonly saving = signal(false);

  onPasswordLogin(user: User): void {
    this.auth.setUser(user);
    if (user.is_staff) {
      this.router.navigate(['/']);
    } else {
      // Сессия уже открыта, но в панель этот аккаунт не пускаем — выходим.
      this.auth.logout().subscribe();
      this.toasts.error('У этого аккаунта нет доступа в админку');
    }
  }

  ngOnInit(): void {
    const token = this.route.snapshot.queryParamMap.get('token');
    if (token) {
      this.stage.set('checking');
      this.api.confirmLogin(token).subscribe({
        next: (res) => {
          this.auth.setUser(res.data);
          if (res.data.is_staff) {
            this.router.navigate(['/']);
          } else {
            this.stage.set('form');
            this.toasts.error('У этого аккаунта нет доступа в админку');
          }
        },
        error: (err) => {
          this.stage.set('form');
          this.toasts.error(errorText(err, 'Ссылка устарела или уже использована.'));
        },
      });
      return;
    }

    // Уже вошли — незачем показывать форму.
    this.auth.restore().subscribe((user) => {
      if (user?.is_staff) this.router.navigate(['/']);
    });
  }

  submit(event: Event): void {
    event.preventDefault();
    const email = this.email.trim().toLowerCase();
    if (!email) {
      this.toasts.error('Укажите адрес почты');
      return;
    }

    this.saving.set(true);
    this.api.requestLogin(email).subscribe({
      next: () => {
        this.email = email;
        this.stage.set('sent');
        this.saving.set(false);
      },
      error: (err) => {
        this.saving.set(false);
        this.toasts.error(errorText(err));
      },
    });
  }
}
