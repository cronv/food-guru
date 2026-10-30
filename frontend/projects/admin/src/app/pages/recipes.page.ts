import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';

import { ApiService } from '@shared/services/api.service';
import { AuthService } from '@shared/services/auth.service';
import { RecipeCard, RecipeStatus, STATUS_LABELS } from '@shared/models/api.models';
import { errorText } from '@shared/utils';
import { ToastService } from '../services/toast.service';

@Component({
    selector: 'adm-recipes',
    imports: [RouterLink, FormsModule],
    template: `
    <header class="head">
      <div>
        <h1>Рецепты</h1>
        <p class="muted">
          {{ total() }} материалов
          @if (!canPublish()) { · показаны только ваши }
        </p>
      </div>
      <a class="btn btn-green" routerLink="/recipes/new">Новый рецепт</a>
    </header>

    <div class="tabs">
      @for (t of tabs; track t.value) {
        <button class="tab" type="button" [class.on]="status === t.value" (click)="setStatus(t.value)">
          {{ t.label }}
        </button>
      }
    </div>

    <div class="filters">
      <input type="search" [(ngModel)]="q" (keyup.enter)="load()" placeholder="Поиск по названию"
             aria-label="Поиск по рецептам">
      <button class="btn btn-ghost btn-sm" type="button" (click)="load()">Найти</button>
    </div>

    @if (loading()) {
      <div class="loading"><div class="spinner"></div></div>
    } @else if (rows().length) {
      <div class="scroll">
        <table>
          <thead>
            <tr>
              <th>Рецепт</th>
              <th>Автор</th>
              <th>Категория</th>
              <th>Статус</th>
              <th class="right">Действия</th>
            </tr>
          </thead>
          <tbody>
            @for (r of rows(); track r.id) {
              <tr>
                <td><a class="name" [routerLink]="['/recipes', r.id]">{{ r.title }}</a></td>
                <td class="muted">{{ r.author?.name || '—' }}</td>
                <td class="muted">{{ r.category?.title || '—' }}</td>
                <td><span class="chip" [class]="chipClass(r.status)">{{ label(r.status) }}</span></td>
                <td class="right">
                  <div class="row end">
                    <a class="btn btn-ghost btn-sm" [routerLink]="['/recipes', r.id]">Открыть</a>
                    @if (r.status === 'moderation' && canPublish()) {
                      <button class="btn btn-green btn-sm" type="button" [disabled]="busy() === r.id"
                              (click)="move(r, 'publish')">Опубликовать</button>
                      <button class="btn btn-ghost btn-sm" type="button" (click)="askReject(r)">Вернуть</button>
                    }
                    @if (r.status === 'draft') {
                      <button class="btn btn-ghost btn-sm" type="button" [disabled]="busy() === r.id"
                              (click)="move(r, 'submit')">На модерацию</button>
                    }
                  </div>
                </td>
              </tr>
            }
          </tbody>
        </table>
      </div>

      @if (page() < lastPage()) {
        <div class="more">
          <button class="btn btn-ghost" type="button" (click)="load(page() + 1)">Показать ещё</button>
        </div>
      }
    } @else {
      <div class="empty">
        <h3>В этом статусе пусто</h3>
        <p class="muted">Поменяйте фильтр или создайте рецепт.</p>
        <a class="btn btn-green" routerLink="/recipes/new">Новый рецепт</a>
      </div>
    }

    @if (rejecting(); as r) {
      <div class="modal" role="dialog" aria-modal="true" aria-label="Вернуть на доработку">
        <div class="box">
          <h2>Вернуть на доработку</h2>
          <p class="muted">«{{ r.title }}» — автор увидит ваш комментарий в редакторе.</p>
          <label class="fl">Что поправить
            <textarea [(ngModel)]="note" rows="3" placeholder="Например: добавьте фото финального шага"></textarea>
          </label>
          <div class="row end">
            <button class="btn btn-ghost btn-sm" type="button" (click)="rejecting.set(null)">Отмена</button>
            <button class="btn btn-primary btn-sm" type="button" (click)="confirmReject(r)">Вернуть</button>
          </div>
        </div>
      </div>
    }
  `,
    styles: [`
    .head { display: flex; flex-wrap: wrap; gap: 16px; align-items: center; justify-content: space-between; margin-bottom: 18px; }
    h1 { font-size: 30px; }
    .head p { margin: 4px 0 0; font-size: 14px; }
    .tabs { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--line); }
    .tab { min-height: 42px; padding: 0 16px; border: 0; border-radius: 999px; background: none; color: var(--muted); font-size: 14px; }
    .tab.on { background: var(--panel); color: #fff; font-weight: 700; }
    .filters { display: flex; gap: 10px; margin-bottom: 16px; max-width: 480px; }
    .name { font-weight: 600; }
    .right { text-align: right; }
    .end { justify-content: flex-end; }
    .more { display: flex; justify-content: center; margin-top: 18px; }
    .loading { display: grid; place-items: center; min-height: 30vh; }
    .modal {
      position: fixed; inset: 0; z-index: 50; background: rgba(12, 11, 8, .6);
      display: grid; place-items: center; padding: 20px;
    }
    .box {
      background: var(--surface); border-radius: 18px; padding: 24px;
      width: min(520px, 100%); display: flex; flex-direction: column; gap: 14px;
    }
    .box h2 { font-size: 22px; }
    .box p { margin: 0; }
  `]
})
export class RecipesPage implements OnInit {
  /** Номер запроса: ответ, отставший от нового отбора, игнорируем,
   *  иначе он перезапишет свежий список устаревшим. */
  private loadSeq = 0;

  private api = inject(ApiService);
  private route = inject(ActivatedRoute);
  private toasts = inject(ToastService);
  private auth = inject(AuthService);

  readonly tabs = [
    { value: 'moderation', label: 'На модерации' },
    { value: 'draft', label: 'Черновики' },
    { value: 'published', label: 'Опубликованы' },
    { value: 'rejected', label: 'Возвращены' },
    { value: 'archived', label: 'Архив' },
    { value: '', label: 'Все' },
  ];

  status = 'moderation';
  q = '';
  note = '';

  readonly rows = signal<RecipeCard[]>([]);
  readonly total = signal(0);
  readonly page = signal(1);
  readonly lastPage = signal(1);
  readonly loading = signal(true);
  readonly busy = signal<number | null>(null);
  readonly rejecting = signal<RecipeCard | null>(null);

  ngOnInit(): void {
    const fromQuery = this.route.snapshot.queryParamMap.get('status');
    if (fromQuery) this.status = fromQuery;
    this.load();
  }

  canPublish(): boolean {
    return this.auth.atLeast('editor_lead');
  }

  label(status: RecipeStatus): string {
    return STATUS_LABELS[status];
  }

  chipClass(status: RecipeStatus): string {
    if (status === 'published') return 'chip-g';
    if (status === 'moderation') return 'chip-b';
    return '';
  }

  setStatus(value: string): void {
    this.status = value;
    this.load();
  }

  load(page = 1): void {
    this.loading.set(true);
    const seq = ++this.loadSeq;

    this.api.adminRecipes({ status: this.status || undefined, q: this.q || undefined, page }).subscribe({
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

  move(r: RecipeCard, action: 'submit' | 'publish' | 'archive'): void {
    this.busy.set(r.id);
    this.api.moveRecipe(r.id, action).subscribe({
      next: (res) => {
        this.busy.set(null);
        this.toasts.show(`Статус: ${res.data.status_label}`);
        this.load();
      },
      error: (err) => {
        this.busy.set(null);
        this.toasts.error(errorText(err));
      },
    });
  }

  askReject(r: RecipeCard): void {
    this.note = '';
    this.rejecting.set(r);
  }

  confirmReject(r: RecipeCard): void {
    if (this.note.trim().length < 5) {
      this.toasts.error('Напишите, что именно нужно поправить');
      return;
    }

    this.api.moveRecipe(r.id, 'reject', this.note.trim()).subscribe({
      next: () => {
        this.rejecting.set(null);
        this.toasts.show('Возвращён на доработку');
        this.load();
      },
      error: (err) => this.toasts.error(errorText(err)),
    });
  }
}
