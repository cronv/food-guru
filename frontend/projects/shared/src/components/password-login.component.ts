import { Component, inject, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';

import { User } from '../models/api.models';
import { ApiService } from '../services/api.service';
import { errorText } from '../utils';

/**
 * Вход по почте и паролю. Общий для сайта и админки: форма сама ходит в API
 * и сообщает наружу только об успехе, а где показывать ошибку, решает она же —
 * рядом с полем, а не всплывающим уведомлением, которое легко пропустить.
 */
@Component({
    selector: 'fg-password-login',
    imports: [FormsModule],
    template: `
    <form (submit)="submit($event)" novalidate>
      <label class="fl" for="pl-email">Email
        <input id="pl-email" type="email" name="email" [(ngModel)]="email" required
               placeholder="anna@example.com" autocomplete="username">
      </label>

      <label class="fl" for="pl-password">Пароль
        <input id="pl-password" [type]="shown() ? 'text' : 'password'" name="password"
               [(ngModel)]="password" required autocomplete="current-password">
      </label>

      <label class="show">
        <input type="checkbox" name="show" [checked]="shown()" (change)="shown.set(!shown())">
        <span>Показать пароль</span>
      </label>

      @if (error()) {
        <p class="err" role="alert">{{ error() }}</p>
      }

      <button class="btn btn-green full" type="submit" [disabled]="saving()">
        {{ saving() ? 'Входим…' : 'Войти' }}
      </button>
    </form>
  `,
    styles: [`
    form { display: flex; flex-direction: column; gap: 14px; }
    .show { display: flex; gap: 8px; align-items: center; min-height: 40px; font-size: 14px; color: var(--muted); cursor: pointer; }
    .err { margin: 0; color: var(--brick); font-size: 14px; }
    .full { width: 100%; }
  `]
})
export class PasswordLoginComponent {
  private api = inject(ApiService);

  /** Вошли успешно: родитель решает, куда вести дальше. */
  readonly loggedIn = output<User>();

  email = '';
  password = '';

  readonly shown = signal(false);
  readonly saving = signal(false);
  readonly error = signal('');

  submit(event: Event): void {
    event.preventDefault();

    const email = this.email.trim().toLowerCase();
    if (!email || !this.password) {
      this.error.set('Введите почту и пароль.');
      return;
    }

    this.saving.set(true);
    this.error.set('');

    this.api.loginWithPassword(email, this.password).subscribe({
      next: (res) => {
        this.saving.set(false);
        this.password = '';
        this.loggedIn.emit(res.data);
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(errorText(err, 'Не удалось войти. Попробуйте ещё раз.'));
      },
    });
  }
}
