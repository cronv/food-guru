import { Component, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';

import { User } from '../models/api.models';
import { ApiService } from '../services/api.service';
import { errorText } from '../utils';

/**
 * Создание пароля или его смена. Первый пароль человек задаёт сразу после
 * перехода по ссылке из письма, смену — из кабинета, и там нужен текущий.
 */
@Component({
    selector: 'fg-password-form',
    imports: [FormsModule],
    template: `
    <form (submit)="submit($event)" novalidate>
      @if (requireCurrent()) {
        <label class="fl" for="pf-current">Текущий пароль
          <input id="pf-current" type="password" name="current" [(ngModel)]="current"
                 autocomplete="current-password">
        </label>
      }

      <label class="fl" for="pf-new">{{ requireCurrent() ? 'Новый пароль' : 'Пароль' }}
        <input id="pf-new" [type]="shown() ? 'text' : 'password'" name="password"
               [(ngModel)]="password" autocomplete="new-password">
        <span class="muted weak">Не короче 8 символов, с буквой и цифрой.</span>
      </label>

      <label class="fl" for="pf-repeat">Повторите пароль
        <input id="pf-repeat" [type]="shown() ? 'text' : 'password'" name="repeat"
               [(ngModel)]="repeat" autocomplete="new-password">
      </label>

      <label class="show">
        <input type="checkbox" name="show" [checked]="shown()" (change)="shown.set(!shown())">
        <span>Показать пароль</span>
      </label>

      @if (error()) {
        <p class="err" role="alert">{{ error() }}</p>
      }

      <button class="btn btn-green" type="submit" [disabled]="saving()">
        {{ saving() ? 'Сохраняем…' : submitLabel() }}
      </button>
    </form>
  `,
    styles: [`
    form { display: flex; flex-direction: column; gap: 14px; }
    .weak { font-weight: 400; }
    .show { display: flex; gap: 8px; align-items: center; min-height: 40px; font-size: 14px; color: var(--muted); cursor: pointer; }
    .err { margin: 0; color: var(--brick); font-size: 14px; }
  `]
})
export class PasswordFormComponent {
  private api = inject(ApiService);

  /** Нужен ли текущий пароль: да при смене, нет при создании первого. */
  readonly requireCurrent = input(false);
  readonly submitLabel = input('Сохранить пароль');

  readonly saved = output<User>();

  current = '';
  password = '';
  repeat = '';

  readonly shown = signal(false);
  readonly saving = signal(false);
  readonly error = signal('');

  submit(event: Event): void {
    event.preventDefault();

    if (this.requireCurrent() && !this.current) {
      this.error.set('Введите текущий пароль.');
      return;
    }
    if (this.password !== this.repeat) {
      this.error.set('Пароли не совпадают.');
      return;
    }

    this.saving.set(true);
    this.error.set('');

    this.api.setPassword({
      password: this.password,
      password_confirmation: this.repeat,
      ...(this.requireCurrent() ? { current_password: this.current } : {}),
    }).subscribe({
      next: (res) => {
        this.saving.set(false);
        this.current = this.password = this.repeat = '';
        this.saved.emit(res.data);
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(errorText(err, 'Не удалось сохранить пароль.'));
      },
    });
  }
}
