import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { isPlatformBrowser } from '@angular/common';
import { PLATFORM_ID } from '@angular/core';

import { PasswordFormComponent } from '@shared/components/password-form.component';
import { User } from '@shared/models/api.models';
import { ApiService } from '@shared/services/api.service';
import { AuthService } from '@shared/services/auth.service';
import { errorText } from '@shared/utils';
import { SeoService } from '../services/seo.service';

/** Страница, на которую ведёт ссылка из письма: /verify/:token и /login/:token. */
@Component({
    selector: 'app-verify',
    imports: [RouterLink, PasswordFormComponent],
    template: `
    <div class="wrap band">
      @switch (state()) {
        @case ('checking') {
          <div class="panel">
            <div class="spinner"></div>
            <h1>Проверяем ссылку</h1>
            <p class="muted">Секунду.</p>
          </div>
        }
        @case ('password') {
          <div class="panel create">
            <div class="icon green">
              <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="var(--on-accent)" stroke-width="2.4">
                <path d="m5 12.5 4.5 4.5L19 7.5" />
              </svg>
            </div>
            <h1>{{ isLogin ? 'Вы вошли' : 'Адрес подтверждён' }}</h1>
            <p>
              Придумайте пароль — в следующий раз можно будет войти по почте и паролю,
              не дожидаясь письма. Войти по ссылке по-прежнему получится.
            </p>
            <div class="form">
              <fg-password-form submitLabel="Создать пароль" (saved)="onPasswordSaved($event)" />
            </div>
            <button class="skip" type="button" (click)="goToAccount()">Пока пропустить</button>
          </div>
        }
        @case ('done') {
          <div class="panel ok">
            <div class="icon green">
              <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="var(--on-accent)" stroke-width="2.4">
                <path d="m5 12.5 4.5 4.5L19 7.5" />
              </svg>
            </div>
            <h1>{{ passwordCreated() ? 'Пароль создан' : 'Адрес подтверждён' }}</h1>
            <p>
              {{ passwordCreated()
                ? 'Теперь можно входить по почте и паролю.'
                : 'Аккаунт активен. Теперь рецепты можно сохранять в избранное.' }}
            </p>
            <a class="btn btn-green" routerLink="/account">В личный кабинет</a>
          </div>
        }
        @case ('expired') {
          <div class="panel bad">
            <div class="icon brick">!</div>
            <h1>Ссылка устарела</h1>
            <p>{{ message() }}</p>
            <a class="btn btn-primary" routerLink="/register">Запросить новую</a>
          </div>
        }
      }
    </div>
  `,
    styles: [`
    .panel {
      max-width: 620px; margin: 0 auto; text-align: center;
      display: flex; flex-direction: column; align-items: center; gap: 14px;
      background: var(--surface); border: 1px solid var(--line);
      border-radius: 20px; padding: clamp(22px, 4vw, 38px);
    }
    .panel.ok { background: var(--green-soft); border-color: transparent; color: var(--green-ink); }
    .panel.ok h1 { color: var(--green-ink); }
    .panel.bad { background: var(--brick-soft); border-color: transparent; color: var(--brick-ink); }
    .panel.bad h1 { color: var(--brick-ink); }
    .panel p { margin: 0; }
    .create { text-align: left; align-items: stretch; }
    .create .icon, .create h1 { align-self: center; }
    .create p { text-align: center; }
    .form { width: 100%; max-width: 420px; align-self: center; }
    .skip {
      align-self: center; border: 0; background: none; color: var(--muted);
      font: inherit; text-decoration: underline; cursor: pointer; min-height: 44px;
    }
    h1 { font-size: 28px; }
    .icon { width: 64px; height: 64px; border-radius: 999px; display: grid; place-items: center; }
    .green { background: var(--green); }
    .brick { background: var(--brick); color: var(--on-accent); font-size: 30px; font-weight: 700; }
  `]
})
export class VerifyPage implements OnInit {
  private api = inject(ApiService);
  private auth = inject(AuthService);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private seo = inject(SeoService);
  private platformId = inject(PLATFORM_ID);

  readonly state = signal<'checking' | 'password' | 'done' | 'expired'>('checking');
  readonly message = signal('Срок действия истёк или ссылкой уже воспользовались.');
  readonly passwordCreated = signal(false);
  isLogin = false;

  onPasswordSaved(user: User): void {
    this.auth.setUser(user);
    this.passwordCreated.set(true);
    this.state.set('done');
    setTimeout(() => this.goToAccount(), 1600);
  }

  goToAccount(): void {
    this.router.navigate(['/account']);
  }

  ngOnInit(): void {
    // Одноразовые ссылки из писем — не для поиска.
    this.seo.apply({ title: 'Подтверждение адреса', path: '/verify', noindex: true });

    const token = this.route.snapshot.paramMap.get('token');
    const isLogin = this.isLogin = this.route.snapshot.data['mode'] === 'login';

    if (!token) {
      this.state.set('expired');
      return;
    }

    // Токен одноразовый: при SSR запрос не делаем, иначе ссылка «сгорит»
    // от предзагрузки мессенджера, не доехав до человека.
    if (!isPlatformBrowser(this.platformId)) return;

    const request = isLogin ? this.api.confirmLogin(token) : this.api.verify(token);

    request.subscribe({
      next: (res) => {
        this.auth.setUser(res.data);

        // Пароля ещё нет — сразу предлагаем его придумать, пока человек здесь.
        if (!res.data.has_password) {
          this.state.set('password');
          return;
        }

        this.state.set('done');
        setTimeout(() => this.goToAccount(), 1600);
      },
      error: (err) => {
        this.message.set(errorText(err, 'Срок действия истёк или ссылкой уже воспользовались.'));
        this.state.set('expired');
      },
    });
  }
}
