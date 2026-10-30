import { Component, OnChanges, SimpleChanges, computed, inject, input, signal } from '@angular/core';

import { Reaction, Recipe } from '@shared/models/api.models';
import { ApiService } from '@shared/services/api.service';
import { AuthService } from '@shared/services/auth.service';
import { errorText } from '@shared/utils';
import { ToastService } from '../services/toast.service';

/**
 * Панель под фото: оценка и просмотры. Сохранение рецепта — в блоке справа.
 *
 * Оценка и просмотры показываются, только когда сервер отдаёт их счётчики:
 * нули-заглушки выглядели бы как настоящая статистика.
 */
@Component({
  selector: 'app-media-actions',
  standalone: true,
  template: `
    <div class="bar">
      @if (hasReactions()) {
        <div class="group" role="group" aria-label="Оценка рецепта">
          <button type="button" class="pill" [class.on]="mine() === 'like'" [attr.aria-pressed]="mine() === 'like'"
                  [disabled]="busy()" (click)="react('like')" [attr.aria-label]="'Нравится, ' + likes()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
              <path d="M7 10v11H3V10zM7 10l4-7c1.7 0 3 1.3 3 3v3h5.3a2 2 0 0 1 2 2.4l-1.5 7.6a2 2 0 0 1-2 1.6H7"/></svg>
            <span class="tnum">{{ likes() }}</span>
          </button>
          <button type="button" class="pill" [class.on]="mine() === 'dislike'" [attr.aria-pressed]="mine() === 'dislike'"
                  [disabled]="busy()" (click)="react('dislike')" [attr.aria-label]="'Не нравится, ' + dislikes()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
              <path d="M17 14V3h4v11zM17 14l-4 7c-1.7 0-3-1.3-3-3v-3H4.7a2 2 0 0 1-2-2.4l1.5-7.6a2 2 0 0 1 2-1.6H17"/></svg>
            <span class="tnum">{{ dislikes() }}</span>
          </button>
        </div>
      }

      @if (viewsTotal() !== undefined) {
        <span class="views muted" [attr.aria-label]="'Просмотров: ' + viewsTotal()">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
          <span class="tnum">{{ viewsText() }}</span>
        </span>
      }
    </div>
  `,
  styles: [`
    .bar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
    .group { display: flex; gap: 6px; }
    .pill {
      display: inline-flex; align-items: center; gap: 6px; min-height: 40px; padding: 0 14px;
      border-radius: 999px; border: 1px solid var(--line); background: var(--surface);
      font: inherit; font-weight: 600; color: var(--ink); cursor: pointer;
    }
    .pill.on { background: var(--green-soft); border-color: var(--green); color: var(--green-ink); }
    .pill:disabled { opacity: .6; cursor: progress; }
    .views { display: inline-flex; align-items: center; gap: 6px; font-size: 14px; }
  `],
})
export class MediaActionsComponent implements OnChanges {
  private api = inject(ApiService);
  private auth = inject(AuthService);
  private toasts = inject(ToastService);

  readonly recipe = input.required<Recipe>();
  /** Свежий счётчик после отметки просмотра; отдельно от recipe, чтобы не сбрасывать оценку. */
  readonly views = input<number | undefined>(undefined);

  readonly likes = signal(0);
  readonly dislikes = signal(0);
  readonly mine = signal<Reaction | null>(null);
  readonly busy = signal(false);

  readonly hasReactions = computed(() => this.recipe().likes_count !== undefined);
  readonly viewsTotal = computed(() => this.views() ?? this.recipe().views_count);
  readonly viewsText = computed(() => new Intl.NumberFormat('ru-RU').format(this.viewsTotal() ?? 0));

  /** Новый рецепт — новые счётчики. До рендера, чтобы не мигали нули. */
  ngOnChanges(changes: SimpleChanges): void {
    if (!changes['recipe']) return;
    const r = this.recipe();
    this.likes.set(r.likes_count ?? 0);
    this.dislikes.set(r.dislikes_count ?? 0);
    this.mine.set(r.my_reaction ?? null);
  }

  react(choice: Reaction): void {
    if (!this.auth.isAuthenticated()) {
      this.toasts.show('Войдите, чтобы оценивать рецепты');
      return;
    }

    const before = { likes: this.likes(), dislikes: this.dislikes(), mine: this.mine() };
    const next = before.mine === choice ? null : choice;

    // Сразу показываем результат, сервер подтвердит или откатим.
    this.apply(before.mine, -1);
    this.apply(next, +1);
    this.mine.set(next);
    this.busy.set(true);

    this.api.react(this.recipe().id, next).subscribe({
      next: (res) => {
        this.likes.set(res.data.likes_count);
        this.dislikes.set(res.data.dislikes_count);
        this.mine.set(res.data.my_reaction);
        this.busy.set(false);
      },
      error: (err) => {
        this.likes.set(before.likes);
        this.dislikes.set(before.dislikes);
        this.mine.set(before.mine);
        this.busy.set(false);
        this.toasts.error(errorText(err, 'Не удалось сохранить оценку.'));
      },
    });
  }

  private apply(reaction: Reaction | null, delta: number): void {
    if (reaction === 'like') this.likes.update((n) => Math.max(0, n + delta));
    if (reaction === 'dislike') this.dislikes.update((n) => Math.max(0, n + delta));
  }
}
