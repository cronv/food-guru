import { Component, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';

import { ReportReason } from '@shared/models/api.models';
import { ApiService } from '@shared/services/api.service';
import { AuthService } from '@shared/services/auth.service';
import { errorText } from '@shared/utils';

export const REPORT_REASONS: { value: ReportReason; label: string }[] = [
  { value: 'error', label: 'Ошибка в рецепте' },
  { value: 'amounts', label: 'Неверные граммовки или время' },
  { value: 'copyright', label: 'Нарушение авторских прав' },
  { value: 'offensive', label: 'Оскорбительное содержание' },
  { value: 'spam', label: 'Спам или реклама' },
  { value: 'other', label: 'Другое' },
];

/** Жалоба на рецепт. Только для вошедших: анонимные жалобы легко поставить на поток. */
@Component({
    selector: 'app-report-form',
    imports: [FormsModule, RouterLink],
    template: `
    @if (sent()) {
      <p class="done" role="status">Спасибо. Редакция посмотрит жалобу и, если нужно, поправит рецепт.</p>
    } @else if (!auth.isAuthenticated()) {
      <p class="muted">
        Пожаловаться могут только зарегистрированные читатели.
        <a routerLink="/login">Войти</a> или <a routerLink="/register">создать аккаунт</a>.
      </p>
    } @else {
      <form (submit)="submit($event)" novalidate>
        <fieldset>
          <legend>Что не так с рецептом?</legend>
          @for (r of reasons; track r.value) {
            <label class="opt">
              <input type="radio" name="reason" [value]="r.value" [(ngModel)]="reason">
              <span>{{ r.label }}</span>
            </label>
          }
        </fieldset>

        <label class="fl" for="report-message">Подробности
          <textarea id="report-message" name="message" rows="3" maxlength="1000" [(ngModel)]="message"
                    [placeholder]="reason === 'other' ? 'Опишите, что не так — обязательно' : 'Необязательно'"></textarea>
        </label>

        @if (error()) { <p class="err" role="alert">{{ error() }}</p> }

        <button class="btn btn-primary" type="submit" [disabled]="saving()">
          {{ saving() ? 'Отправляем…' : 'Отправить жалобу' }}
        </button>
      </form>
    }
  `,
    styles: [`
    form { display: flex; flex-direction: column; gap: 14px; max-width: 520px; }
    fieldset { border: 0; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
    legend { font-weight: 700; margin-bottom: 6px; }
    .opt { display: flex; gap: 10px; align-items: center; min-height: 36px; }
    .err { margin: 0; color: var(--brick); font-size: 14px; }
    .done { margin: 0; color: var(--green-ink); font-weight: 600; }
    .btn { align-self: flex-start; }
  `]
})
export class ReportFormComponent {
  private api = inject(ApiService);
  auth = inject(AuthService);

  readonly slug = input.required<string>();
  readonly reasons = REPORT_REASONS;

  reason: ReportReason | null = null;
  message = '';

  readonly saving = signal(false);
  readonly sent = signal(false);
  readonly error = signal('');

  submit(event: Event): void {
    event.preventDefault();

    if (!this.reason) {
      this.error.set('Выберите причину.');
      return;
    }
    const message = this.message.trim();
    if (this.reason === 'other' && message.length < 10) {
      this.error.set('Опишите проблему хотя бы парой предложений.');
      return;
    }

    this.saving.set(true);
    this.error.set('');
    this.api.reportRecipe(this.slug(), { reason: this.reason, message }).subscribe({
      next: () => {
        this.saving.set(false);
        this.sent.set(true);
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(errorText(err, 'Не удалось отправить жалобу. Попробуйте позже.'));
      },
    });
  }
}
