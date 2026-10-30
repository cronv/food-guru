import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';

import { PasswordLoginComponent } from '@shared/components/password-login.component';
import { User } from '@shared/models/api.models';
import { ApiService } from '@shared/services/api.service';
import { AuthService } from '@shared/services/auth.service';
import { errorText } from '@shared/utils';
import { SeoService } from '../services/seo.service';
import { ToastService } from '../services/toast.service';

@Component({
    selector: 'app-auth',
    imports: [RouterLink, FormsModule, PasswordLoginComponent],
    template: `
    <div class="wrap band">
      @if (stage() === 'sent') {
        <div class="panel center">
          <div class="icon">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="1.6">
              <rect x="3" y="5.5" width="18" height="13" rx="2.5" /><path d="m3.8 7 8.2 6 8.2-6" />
            </svg>
          </div>
          <h1>Письмо отправлено</h1>
          <p class="muted">
            Мы написали на <strong>{{ email }}</strong>. Перейдите по ссылке из письма, чтобы
            {{ isLogin ? 'войти' : 'подтвердить адрес' }}.
          </p>
          <button class="btn btn-ghost" type="button" [disabled]="cooldown() > 0 || resending()"
                  (click)="resend()">
            {{ cooldown() > 0 ? 'Отправить ещё раз через ' + cooldown() : 'Отправить ещё раз' }}
          </button>
          <p class="muted small">Ссылка одноразовая и живёт {{ isLogin ? '15 минут' : '24 часа' }}.</p>
        </div>
      } @else {
        <div class="layout">
          <div class="panel">
            <h1>{{ isLogin ? 'Вход' : 'Создать аккаунт' }}</h1>

            @if (isLogin) {
              <div class="tabs" role="tablist" aria-label="Способ входа">
                <button type="button" role="tab" [attr.aria-selected]="method() === 'password'"
                        [class.on]="method() === 'password'" (click)="method.set('password')">
                  По паролю
                </button>
                <button type="button" role="tab" [attr.aria-selected]="method() === 'link'"
                        [class.on]="method() === 'link'" (click)="method.set('link')">
                  По ссылке из письма
                </button>
              </div>
            } @else {
              <p class="muted">
                Пришлём ссылку на почту. Перейдя по ней, вы придумаете пароль.
              </p>
            }

            @if (isLogin && method() === 'password') {
              <fg-password-login (loggedIn)="onPasswordLogin($event)" />
              <p class="muted small">
                Забыли пароль или ещё не задавали? Войдите по ссылке из письма —
                после неё можно придумать новый.
              </p>
            } @else {
              <form class="inner" (submit)="submit($event)">
                @if (!isLogin) {
                  <label class="fl" for="name">Имя
                    <input id="name" type="text" name="name" [(ngModel)]="name" required minlength="2"
                           placeholder="Анна" autocomplete="name">
                  </label>
                }

                <label class="fl" for="email">Email
                  <input id="email" type="email" name="email" [(ngModel)]="email" required
                         placeholder="anna@example.com" autocomplete="email">
                  <span class="muted weak">На этот адрес придёт ссылка.</span>
                </label>

                @if (!isLogin) {
                  <label class="terms">
                    <input type="checkbox" name="terms" [(ngModel)]="terms">
                    <span>Соглашаюсь с условиями использования и обработкой персональных данных</span>
                  </label>
                }

                <button class="btn btn-green" type="submit" [disabled]="saving()">
                  {{ saving() ? 'Отправляем…' : 'Отправить ссылку на почту' }}
                </button>
              </form>
            }

            <p class="muted small">
              @if (isLogin) {
                Нет аккаунта? <a routerLink="/register">Зарегистрироваться</a>
              } @else {
                Уже есть аккаунт? <a routerLink="/login">Войти</a>
              }
            </p>
          </div>

          <aside class="how">
            <h2>Как это работает</h2>
            <ol>
              <li><span class="num">1</span><span>Вы оставляете почту — аккаунт создаётся со статусом «не подтверждён».</span></li>
              <li><span class="num">2</span><span>На адрес уходит письмо с уникальной ссылкой.</span></li>
              <li><span class="num">3</span><span>После перехода вы придумываете пароль — открываются избранное и отзывы.</span></li>
              <li><span class="num">4</span><span>В следующий раз заходите по паролю или по новой ссылке из письма.</span></li>
            </ol>
          </aside>
        </div>
      }
    </div>
  `,
    styles: [`
    .layout { display: flex; flex-wrap: wrap; gap: 26px; align-items: stretch; }
    .panel {
      flex: 1 1 400px; min-width: 0; background: var(--surface);
      border: 1px solid var(--line); border-radius: 20px;
      padding: clamp(20px, 3vw, 34px); display: flex; flex-direction: column; gap: 16px;
    }
    .panel h1 { font-size: 32px; }
    .panel p { margin: 0; }
    .inner { display: flex; flex-direction: column; gap: 16px; }
    .tabs {
      display: grid; grid-template-columns: 1fr 1fr; gap: 4px; padding: 4px;
      background: var(--bg-soft, var(--line)); border-radius: 12px;
    }
    .tabs button {
      min-height: 44px; border: 0; border-radius: 9px; background: none;
      font: inherit; font-weight: 600; color: var(--muted); cursor: pointer;
    }
    .tabs button.on { background: var(--surface); color: var(--ink); box-shadow: 0 1px 3px rgb(0 0 0 / .12); }
    .weak { font-weight: 400; }
    .small { font-size: 13px; }
    .terms { display: flex; gap: 10px; align-items: flex-start; font-size: 14px; color: var(--muted); }
    .center { max-width: 620px; margin: 0 auto; text-align: center; align-items: center; }
    .icon { width: 64px; height: 64px; border-radius: 999px; background: var(--green-soft); display: grid; place-items: center; }
    .how {
      flex: 1 1 320px; min-width: 0; background: var(--green-soft); color: var(--green-ink);
      border-radius: 20px; padding: clamp(20px, 3vw, 34px);
    }
    .how h2 { font-size: 25px; color: var(--green-ink); margin-bottom: 16px; }
    .how ol { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 14px; }
    .how li { display: flex; gap: 12px; align-items: flex-start; font-size: 15px; }
    .num {
      flex: 0 0 auto; width: 32px; height: 32px; border-radius: 999px;
      background: var(--green); color: var(--on-accent); display: grid; place-items: center; font-weight: 700;
    }
  `]
})
export class AuthPage implements OnInit {
  private api = inject(ApiService);
  private route = inject(ActivatedRoute);
  private seo = inject(SeoService);
  private toasts = inject(ToastService);
  private auth = inject(AuthService);
  private router = inject(Router);

  name = '';
  email = '';
  terms = false;
  isLogin = false;

  /** Способ входа: по паролю или по ссылке. Тем, у кого пароля ещё нет, нужна ссылка. */
  readonly method = signal<'password' | 'link'>('password');
  readonly stage = signal<'form' | 'sent'>('form');
  readonly saving = signal(false);
  readonly resending = signal(false);
  readonly cooldown = signal(0);

  ngOnInit(): void {
    this.isLogin = this.route.snapshot.data['mode'] === 'login';
    this.seo.apply({
      title: this.isLogin ? 'Вход' : 'Регистрация',
      description: 'Вход по паролю или по ссылке из письма.',
      path: this.isLogin ? '/login' : '/register',
    });
  }

  onPasswordLogin(user: User): void {
    this.auth.setUser(user);
    this.router.navigate(['/account']);
  }

  submit(event: Event): void {
    event.preventDefault();

    const email = this.email.trim().toLowerCase();
    if (!email) {
      this.toasts.error('Укажите адрес почты');
      return;
    }
    if (!this.isLogin && !this.terms) {
      this.toasts.error('Нужно согласие на обработку данных');
      return;
    }

    this.saving.set(true);
    const request = this.isLogin
      ? this.api.requestLogin(email)
      : this.api.register(this.name.trim(), email);

    request.subscribe({
      next: () => {
        this.email = email;
        this.stage.set('sent');
        this.saving.set(false);
        this.startCooldown();
      },
      error: (err) => {
        this.saving.set(false);
        this.toasts.error(errorText(err));
      },
    });
  }

  resend(): void {
    this.resending.set(true);
    this.api.resend(this.email).subscribe({
      next: () => {
        this.resending.set(false);
        this.startCooldown();
        this.toasts.show('Письмо отправлено заново');
      },
      error: (err) => {
        this.resending.set(false);
        this.toasts.error(errorText(err, 'Письмо недавно уже отправляли. Подождите минуту.'));
      },
    });
  }

  /** Минутный интервал между письмами — такой же, как на сервере. */
  private startCooldown(): void {
    this.cooldown.set(60);
    const timer = setInterval(() => {
      this.cooldown.update((n) => n - 1);
      if (this.cooldown() <= 0) clearInterval(timer);
    }, 1000);
  }
}
