import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';

import { CookbookNote, RecipeCard } from '@shared/models/api.models';
import { ApiService } from '@shared/services/api.service';
import { AuthService } from '@shared/services/auth.service';
import { errorText, formatShortDate, pluralize } from '@shared/utils';
import { BreadcrumbsComponent } from '../components/breadcrumbs.component';
import { RecipeCardComponent } from '../components/recipe-card.component';
import { SeoService } from '../services/seo.service';
import { ToastService } from '../services/toast.service';

/**
 * Рецептосохранялка — личная кулинарная книга. Две части: рецепты сайта,
 * сохранённые кнопкой «В рецептосохранялку», и свои заметки — рецепт с другого
 * сайта по ссылке или записанный от руки. Избранное — отдельный список в кабинете.
 */
@Component({
    selector: 'app-cookbook',
    imports: [FormsModule, RouterLink, RecipeCardComponent, BreadcrumbsComponent],
    template: `
    <div class="wrap band">
      <app-breadcrumbs [items]="[{ label: 'Главная', link: '/' }, { label: 'Личный кабинет', link: '/account' }, { label: 'Рецептосохранялка' }]" />
      <h1>Рецептосохранялка</h1>
      <p class="muted lead">
        Ваша кулинарная книга: рецепты с сайта и свои записи — в том числе рецепты с других сайтов.
      </p>

      @if (!auth.loaded()) {
        <div class="state"><div class="spinner"></div></div>
      } @else if (!auth.isAuthenticated()) {
        <div class="state empty">
          <h2>Нужен вход</h2>
          <p class="muted">Войдите, чтобы сохранять рецепты и вести свои заметки.</p>
          <div class="row">
            <a class="btn btn-green" routerLink="/login">Войти</a>
            <a class="btn btn-ghost" routerLink="/register">Создать аккаунт</a>
          </div>
        </div>
      } @else if (!auth.isVerified()) {
        <div class="note-warn block">Подтвердите адрес почты — после этого рецептосохранялка откроется.</div>
      } @else {
        <div class="tabs" role="tablist" aria-label="Разделы рецептосохранялки">
          <button type="button" role="tab" [attr.aria-selected]="tab() === 'saved'" [class.on]="tab() === 'saved'"
                  (click)="tab.set('saved')">
            Сохранённые рецепты <span class="cnt tnum">{{ savedTotal() }}</span>
          </button>
          <button type="button" role="tab" [attr.aria-selected]="tab() === 'notes'" [class.on]="tab() === 'notes'"
                  (click)="tab.set('notes')">
            Мои заметки <span class="cnt tnum">{{ notesTotal() }}</span>
          </button>
        </div>

        @if (tab() === 'saved') {
          @if (saved().length) {
            <div class="grid">
              @for (r of saved(); track r.slug) { <app-recipe-card [recipe]="r" /> }
            </div>
            @if (savedPage() < savedLast()) {
              <div class="more"><button class="btn btn-line" type="button" (click)="loadSaved(savedPage() + 1)">Показать ещё</button></div>
            }
          } @else if (savedLoading()) {
            <div class="state"><div class="spinner"></div></div>
          } @else {
            <div class="state empty">
              <h2>Пока пусто</h2>
              <p class="muted">Откройте рецепт и нажмите «В рецептосохранялку» — он появится здесь.</p>
              <a class="btn btn-ghost" routerLink="/recipes">К каталогу</a>
            </div>
          }
        } @else {
          <form class="editor" (submit)="saveNote($event)">
            <h2>{{ editingId() ? 'Изменить заметку' : 'Новая заметка' }}</h2>
            <label class="fl" for="note-title">Название
              <input id="note-title" name="title" [(ngModel)]="title" maxlength="200" placeholder="Бабушкин борщ">
            </label>
            <label class="fl" for="note-url">Ссылка на рецепт
              <input id="note-url" name="url" type="url" [(ngModel)]="url" maxlength="2048" placeholder="https://…">
              <span class="muted weak">Если рецепт на другом сайте. Можно оставить пустым и записать текст.</span>
            </label>
            <label class="fl" for="note-body">Рецепт или заметка
              <textarea id="note-body" name="body" rows="5" [(ngModel)]="body" maxlength="20000"
                        placeholder="Ингредиенты, шаги, свои пометки"></textarea>
            </label>
            @if (formError()) { <p class="err" role="alert">{{ formError() }}</p> }
            <div class="row">
              <button class="btn btn-green" type="submit" [disabled]="saving()">
                {{ saving() ? 'Сохраняем…' : (editingId() ? 'Сохранить изменения' : 'Добавить в рецептосохранялку') }}
              </button>
              @if (editingId()) {
                <button class="btn btn-ghost" type="button" (click)="resetForm()">Отмена</button>
              }
            </div>
          </form>

          @if (notes().length) {
            <ul class="notes">
              @for (n of notes(); track n.id) {
                <li class="item">
                  <div class="item-head">
                    <h3>{{ n.title }}</h3>
                    <span class="muted when">{{ when(n.updated_at) }}</span>
                  </div>
                  @if (n.url) {
                    <a class="link" [href]="n.url" target="_blank" rel="noopener noreferrer nofollow">{{ host(n.url) }} ↗</a>
                  }
                  @if (n.body) { <p class="body">{{ n.body }}</p> }
                  <div class="row actions">
                    <button class="btn btn-ghost btn-sm" type="button" (click)="edit(n)">Изменить</button>
                    @if (confirmId() === n.id) {
                      <span class="muted">Удалить заметку?</span>
                      <button class="btn btn-sm danger" type="button" (click)="remove(n)">Да, удалить</button>
                      <button class="btn btn-ghost btn-sm" type="button" (click)="confirmId.set(null)">Нет</button>
                    } @else {
                      <button class="btn btn-ghost btn-sm" type="button" (click)="confirmId.set(n.id)">Удалить</button>
                    }
                  </div>
                </li>
              }
            </ul>
          } @else if (!notesLoading()) {
            <p class="muted">Своих заметок пока нет. Добавьте первую — например, рецепт, который прислала подруга.</p>
          }
        }
      }
    </div>
  `,
    styles: [`
    h1 { font-size: clamp(28px, 4vw, 38px); }
    .lead { margin: 8px 0 22px; max-width: 64ch; }
    .tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 20px; }
    .tabs button {
      display: inline-flex; align-items: center; gap: 8px; min-height: 44px; padding: 0 16px;
      border-radius: 999px; border: 1px solid var(--line); background: var(--surface);
      font: inherit; font-weight: 600; color: var(--ink); cursor: pointer;
    }
    .tabs button.on { background: var(--green); border-color: var(--green); color: var(--on-accent); }
    .cnt { min-width: 22px; height: 22px; padding: 0 6px; border-radius: 999px; font-size: 12px; background: var(--sunken); color: var(--ink); display: inline-grid; place-items: center; }
    .tabs button.on .cnt { background: rgb(255 255 255 / .22); color: inherit; }
    .state { display: grid; place-items: center; gap: 10px; min-height: 200px; text-align: center; }
    .block { margin-top: 10px; }
    .more { display: grid; place-items: center; margin-top: 24px; }
    .editor {
      max-width: 640px; display: flex; flex-direction: column; gap: 14px; padding: 20px;
      background: var(--surface); border: 1px solid var(--line); border-radius: 18px; margin-bottom: 24px;
    }
    .editor h2 { font-size: 20px; margin: 0; }
    .weak { font-weight: 400; }
    .err { margin: 0; color: var(--brick); font-size: 14px; }
    .notes { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 12px; max-width: 760px; }
    .item { padding: 16px 18px; background: var(--surface); border: 1px solid var(--line); border-radius: 16px; }
    .item-head { display: flex; align-items: baseline; gap: 12px; }
    .item-head h3 { margin: 0; font-family: var(--body); font-size: 17px; font-weight: 700; }
    .when { margin-left: auto; font-size: 12px; white-space: nowrap; }
    .link { display: inline-block; margin-top: 6px; font-size: 14px; overflow-wrap: anywhere; }
    .body { margin: 8px 0 0; white-space: pre-line; line-height: 1.55; overflow-wrap: anywhere; }
    .actions { margin-top: 10px; align-items: center; }
    .danger { background: var(--brick); color: var(--on-accent); }
  `]
})
export class CookbookPage implements OnInit {
  private api = inject(ApiService);
  private seo = inject(SeoService);
  private toasts = inject(ToastService);
  readonly auth = inject(AuthService);

  readonly tab = signal<'saved' | 'notes'>('saved');

  readonly saved = signal<RecipeCard[]>([]);
  readonly savedTotal = signal(0);
  readonly savedPage = signal(1);
  readonly savedLast = signal(1);
  readonly savedLoading = signal(true);

  readonly notes = signal<CookbookNote[]>([]);
  readonly notesTotal = signal(0);
  readonly notesLoading = signal(true);

  readonly editingId = signal<number | null>(null);
  readonly confirmId = signal<number | null>(null);
  readonly saving = signal(false);
  readonly formError = signal('');

  title = '';
  url = '';
  body = '';

  ngOnInit(): void {
    this.seo.apply({ title: 'Рецептосохранялка', path: '/cookbook' });

    // Профиль может ещё грузиться: ждём его, а не показываем «нужен вход» вошедшему.
    if (this.auth.loaded()) {
      this.start();
    } else {
      this.auth.restore().subscribe(() => this.start());
    }
  }

  private start(): void {
    if (!this.auth.isVerified()) return;
    this.loadSaved(1);
    this.loadNotes();
  }

  loadSaved(page: number): void {
    this.savedLoading.set(true);
    this.api.cookbookRecipes(page).subscribe({
      next: (res) => {
        this.saved.set(page === 1 ? res.data : [...this.saved(), ...res.data]);
        this.savedTotal.set(res.meta.total);
        this.savedPage.set(res.meta.current_page);
        this.savedLast.set(res.meta.last_page);
        this.savedLoading.set(false);
      },
      error: (err) => {
        this.savedLoading.set(false);
        this.toasts.error(errorText(err));
      },
    });
  }

  private loadNotes(): void {
    this.api.cookbookNotes().subscribe({
      next: (res) => {
        this.notes.set(res.data);
        this.notesTotal.set(res.meta.total);
        this.notesLoading.set(false);
      },
      error: () => this.notesLoading.set(false),
    });
  }

  saveNote(event: Event): void {
    event.preventDefault();

    const title = this.title.trim();
    const url = this.url.trim() || null;
    const body = this.body.trim() || null;

    if (title.length < 2) {
      this.formError.set('Назовите рецепт.');
      return;
    }
    if (!url && !body) {
      this.formError.set('Добавьте ссылку на рецепт или запишите его текст.');
      return;
    }

    this.saving.set(true);
    this.formError.set('');
    const id = this.editingId() ?? undefined;

    this.api.saveCookbookNote({ title, url, body }, id).subscribe({
      next: (res) => {
        this.saving.set(false);
        if (id) {
          this.notes.update((list) => list.map((n) => (n.id === id ? res.data : n)));
          this.toasts.show('Заметка обновлена');
        } else {
          this.notes.update((list) => [res.data, ...list]);
          this.notesTotal.update((n) => n + 1);
          this.toasts.show('Добавлено в рецептосохранялку');
        }
        this.resetForm();
      },
      error: (err) => {
        this.saving.set(false);
        this.formError.set(errorText(err));
      },
    });
  }

  edit(note: CookbookNote): void {
    this.editingId.set(note.id);
    this.title = note.title;
    this.url = note.url ?? '';
    this.body = note.body ?? '';
    this.formError.set('');
  }

  resetForm(): void {
    this.editingId.set(null);
    this.title = this.url = this.body = '';
    this.formError.set('');
  }

  remove(note: CookbookNote): void {
    this.api.deleteCookbookNote(note.id).subscribe({
      next: () => {
        this.notes.update((list) => list.filter((n) => n.id !== note.id));
        this.notesTotal.update((n) => Math.max(0, n - 1));
        this.confirmId.set(null);
        if (this.editingId() === note.id) this.resetForm();
      },
      error: (err) => this.toasts.error(errorText(err)),
    });
  }

  host(url: string): string {
    try {
      return new URL(url).hostname.replace(/^www\./, '');
    } catch {
      return url;
    }
  }

  when(iso: string | null): string {
    return formatShortDate(iso);
  }

  notesLabel(n: number): string {
    return pluralize(n, 'заметка', 'заметки', 'заметок');
  }
}
