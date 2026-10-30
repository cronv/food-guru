import { Component, OnInit, inject, signal } from '@angular/core';

import { ApiService } from '@shared/services/api.service';
import { ActivityRow } from '@shared/models/api.models';
import { errorText, formatShortDate } from '@shared/utils';
import { ToastService } from '../services/toast.service';

const ACTION_LABELS: Record<string, string> = {
  'recipe.created': 'создал рецепт',
  'recipe.updated': 'изменил рецепт',
  'recipe.submitted': 'отправил на модерацию',
  'recipe.published': 'опубликовал',
  'recipe.rejected': 'вернул на доработку',
  'recipe.archived': 'отправил в архив',
  'review.created': 'оставил отзыв',
  'user.role_changed': 'изменил роль',
  'user.enabled': 'включил аккаунт',
  'user.disabled': 'отключил аккаунт',
  'user.verification_resent': 'выслал письмо подтверждения',
  'email.verified': 'подтвердил почту',
  'email.change_requested': 'запросил смену почты',
  'auth.login': 'вошёл',
  'auth.login_password': 'вошёл по паролю',
  'password.created': 'создал пароль',
  'password.changed': 'сменил пароль',
  'recipe.reel_uploaded': 'загрузил ролик к рецепту',
  'recipe.reel_deleted': 'удалил ролик рецепта',
  'recipe.reported': 'пожаловался на рецепт',
  'report.resolved': 'закрыл жалобу на рецепт',
  'report.rejected': 'отклонил жалобу на рецепт',
  'book.created': 'создал книгу',
  'book.updated': 'изменил книгу',
  'book.purchased': 'купил книгу',
  'product.created': 'добавил товар',
  'product.updated': 'изменил товар',
  'order.placed': 'оформил заказ',
  'order.assembling': 'передал заказ в сборку',
  'order.shipped': 'отправил заказ',
  'order.delivered': 'отметил заказ доставленным',
  'order.canceled': 'отменил заказ',
  'club.created': 'добавил материал закрытой академии',
  'club.updated': 'изменил материал закрытой академии',
  'club.video_uploaded': 'загрузил видео в закрытую академию',
  'subscription.purchased': 'оформил подписку',
  'wallet.adjusted': 'изменил баланс пользователя',
};

@Component({
  selector: 'adm-activity',
  standalone: true,
  template: `
    <header class="head">
      <h1>Журнал действий</h1>
      <p class="muted">{{ total() }} записей. Пишется всё, что меняет контент или права.</p>
    </header>

    @if (loading() && !rows().length) {
      <div class="loading"><div class="spinner"></div></div>
    } @else if (rows().length) {
      <div class="log">
        @for (row of rows(); track row.id) {
          <div class="entry">
            <span class="dot" [class]="dotClass(row.action)"></span>
            <div class="body">
              <p class="line">
                <strong>{{ row.actor?.name || 'Система' }}</strong>
                {{ label(row.action) }}
                @if (subject(row); as s) { <span class="subject">{{ s }}</span> }
              </p>
              <span class="muted when">{{ when(row.created_at) }}</span>
            </div>
          </div>
        }
      </div>

      @if (page() < lastPage()) {
        <div class="more">
          <button class="btn btn-ghost" type="button" (click)="load(page() + 1)">Показать ещё</button>
        </div>
      }
    } @else {
      <div class="empty"><h3>Журнал пуст</h3><p class="muted">Действия появятся после первых правок.</p></div>
    }
  `,
  styles: [`
    .head { margin-bottom: 22px; }
    h1 { font-size: 30px; }
    .head p { margin: 4px 0 0; font-size: 14px; }
    .log { background: var(--surface); border: 1px solid var(--line); border-radius: 16px; padding: 8px 22px; }
    .entry { display: flex; gap: 14px; padding: 14px 0; border-bottom: 1px solid var(--line); }
    .entry:last-child { border-bottom: 0; }
    .dot { flex: 0 0 auto; width: 8px; height: 8px; margin-top: 7px; border-radius: 999px; background: var(--muted); }
    .dot.good { background: var(--green); }
    .dot.warn { background: var(--brick); }
    .body { min-width: 0; }
    .line { margin: 0; font-size: 14px; line-height: 1.5; }
    .subject { color: var(--muted); }
    .when { font-size: 12px; }
    .more { display: flex; justify-content: center; margin-top: 18px; }
    .loading { display: grid; place-items: center; min-height: 30vh; }
  `],
})
export class ActivityPage implements OnInit {
  /** Номер запроса: ответ, отставший от нового отбора, игнорируем,
   *  иначе он перезапишет свежий список устаревшим. */
  private loadSeq = 0;

  private api = inject(ApiService);
  private toasts = inject(ToastService);

  readonly rows = signal<ActivityRow[]>([]);
  readonly total = signal(0);
  readonly page = signal(1);
  readonly lastPage = signal(1);
  readonly loading = signal(true);

  ngOnInit(): void {
    this.load();
  }

  load(page = 1): void {
    this.loading.set(true);
    const seq = ++this.loadSeq;

    this.api.activity(page).subscribe({
      next: (res) => {
        if (seq !== this.loadSeq) return;

        this.rows.set(page === 1 ? res.data : [...this.rows(), ...res.data]);
        this.total.set(res.meta.total);
        this.page.set(res.meta.current_page);
        this.lastPage.set(res.meta.last_page);
        this.loading.set(false);
      },
      error: (err) => {
        if (seq !== this.loadSeq) return;

        this.loading.set(false);
        this.toasts.error(errorText(err));
      },
    });
  }

  label(action: string): string {
    return ACTION_LABELS[action] ?? action;
  }

  subject(row: ActivityRow): string {
    const payload = row.payload as { title?: string; email?: string; to?: string } | null;
    if (!payload) return '';
    if (payload.title) return `«${payload.title}»`;
    if (payload.email) return payload.to ? `${payload.email} → ${payload.to}` : payload.email;
    return '';
  }

  dotClass(action: string): string {
    if (action.endsWith('published') || action.endsWith('verified')) return 'good';
    if (action.endsWith('rejected') || action.endsWith('disabled') || action.endsWith('reported')) return 'warn';
    return '';
  }

  when(iso: string | null): string {
    return formatShortDate(iso);
  }
}
