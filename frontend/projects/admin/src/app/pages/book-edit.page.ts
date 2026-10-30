import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';

import { ApiService } from '@shared/services/api.service';
import { CommerceService } from '@shared/services/commerce.service';
import { errorText } from '@shared/utils';
import { ToastService } from '../services/toast.service';

interface ChapterRow { title: string; body: string; is_preview: boolean }
interface CoverRef { id: number; url: string; alt: string | null }

const emptyChapter = (): ChapterRow => ({ title: '', body: '', is_preview: false });

@Component({
    selector: 'adm-book-edit',
    imports: [RouterLink, FormsModule],
    template: `
    <nav class="crumbs muted">
      <a routerLink="/books">Книги</a> / <span>{{ title || 'Новая книга' }}</span>
    </nav>

    <header class="head">
      <div class="row ident">
        <h1>{{ title || 'Новая книга' }}</h1>
        @if (id()) {
          <span class="chip" [class.chip-g]="savedPublished()">{{ savedPublished() ? 'Опубликована' : 'Черновик' }}</span>
        }
      </div>
      <button class="btn btn-green" type="button" [disabled]="saving()" (click)="save()">
        {{ saving() ? 'Сохраняем…' : 'Сохранить' }}
      </button>
    </header>

    <div class="layout">
      <div class="main">
        <section class="panel">
          <h2>Основное</h2>

          <label class="fl" for="b-title">Название
            <input id="b-title" type="text" name="b-title" [(ngModel)]="title" placeholder="Например, Хлеб дома">
          </label>

          <label class="fl" for="b-slug">Адрес
            <input id="b-slug" type="text" name="b-slug" [(ngModel)]="slug" placeholder="hleb-doma">
            <span class="muted weak">Пустое поле — адрес соберётся из названия.</span>
          </label>

          <label class="fl" for="b-author">Автор
            <input id="b-author" type="text" name="b-author" [(ngModel)]="authorName" placeholder="Имя и фамилия">
          </label>

          <!-- Год и страна — фильтры раздела книг на сайте. -->
          <div class="row-2">
            <label class="fl" for="b-year">Год издания
              <input id="b-year" type="number" name="b-year" min="1800" max="2100" [(ngModel)]="year" placeholder="2024">
            </label>
            <label class="fl" for="b-country">Страна
              <input id="b-country" type="text" name="b-country" [(ngModel)]="country" placeholder="Россия">
            </label>
          </div>

          <label class="fl" for="b-lead">Краткое описание
            <textarea id="b-lead" name="b-lead" rows="2" [(ngModel)]="lead"
                      placeholder="Пара предложений для карточки книги"></textarea>
          </label>

          <label class="fl" for="b-desc">Полное описание
            <textarea id="b-desc" name="b-desc" rows="5" [(ngModel)]="description"
                      placeholder="О чём книга и кому она пригодится"></textarea>
          </label>
        </section>

        <section class="panel">
          <div class="sec-row">
            <h2>Главы</h2>
            <button class="btn btn-ghost btn-sm" type="button" (click)="addChapter()">+ Глава</button>
          </div>
          <p class="muted weak">
            Главы сохраняются целиком в этом порядке. «Ознакомительная» глава открыта всем —
            так читатель может попробовать книгу до покупки.
          </p>

          @for (ch of chapters(); track $index; let i = $index; let last = $last) {
            <div class="chapter">
              <div class="ch-top">
                <span class="num">{{ i + 1 }}</span>
                <label class="fl grow">Название главы
                  <input type="text" [(ngModel)]="ch.title" [name]="'ch-title-' + i" placeholder="Глава {{ i + 1 }}">
                </label>
              </div>
              <label class="fl">Текст
                <textarea rows="8" [(ngModel)]="ch.body" [name]="'ch-body-' + i"
                          placeholder="Текст главы. Абзацы разделяйте пустой строкой."></textarea>
              </label>
              <div class="ch-bottom">
                <label class="check">
                  <input type="checkbox" [(ngModel)]="ch.is_preview" [name]="'ch-prev-' + i"> Ознакомительная
                </label>
                <div class="ch-tools">
                  <button class="tool" type="button" [disabled]="i === 0" (click)="moveChapter(i, -1)"
                          [attr.aria-label]="'Поднять главу ' + (i + 1)">↑</button>
                  <button class="tool" type="button" [disabled]="last" (click)="moveChapter(i, 1)"
                          [attr.aria-label]="'Опустить главу ' + (i + 1)">↓</button>
                  <button class="tool del" type="button" (click)="removeChapter(i)"
                          [attr.aria-label]="'Удалить главу ' + (i + 1)">✕</button>
                </div>
              </div>
            </div>
          } @empty {
            <p class="muted weak">Глав пока нет.</p>
          }
        </section>
      </div>

      <aside class="side">
        <section class="panel">
          <h2>Продажа</h2>
          <label class="fl" for="b-price">Цена, ₽
            <input id="b-price" type="number" name="b-price" min="0" step="0.01" [(ngModel)]="price">
            <span class="muted weak">0 — книга бесплатная.</span>
          </label>
          <label class="check">
            <input type="checkbox" name="b-sub" [(ngModel)]="inSubscription"> Входит в подписку закрытой академии
          </label>
          <label class="check">
            <input type="checkbox" name="b-pub" [(ngModel)]="isPublished"> Опубликовать на сайте
          </label>
        </section>

        <section class="panel">
          <h2>Обложка</h2>
          @if (cover(); as c) {
            <div class="cover">
              <img [src]="c.url" [alt]="c.alt || 'Обложка книги'">
              <button class="btn btn-ghost btn-sm" type="button" (click)="cover.set(null)">Убрать</button>
            </div>
          } @else {
            <label class="upload">
              {{ uploading() ? 'Загружаем…' : '+ Загрузить обложку' }}
              <input class="hidden-file" type="file" accept="image/*"
                     [disabled]="uploading()" (change)="uploadCover($event)">
            </label>
          }
          <p class="muted weak">JPEG, PNG или WebP до 8 МБ.</p>
        </section>
      </aside>
    </div>
  `,
    styles: [`
    .row-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }
    .crumbs { font-size: 13px; margin-bottom: 12px; }
    .head { display: flex; flex-wrap: wrap; gap: 16px; align-items: center; justify-content: space-between; margin-bottom: 20px; }
    .ident { align-items: center; gap: 14px; min-width: 0; }
    h1 { font-size: 28px; overflow-wrap: anywhere; }
    .layout { display: flex; flex-wrap: wrap; gap: 22px; align-items: flex-start; }
    .main { flex: 999 1 520px; min-width: 0; display: flex; flex-direction: column; gap: 20px; }
    .side { flex: 1 1 300px; min-width: 0; display: flex; flex-direction: column; gap: 20px; }
    .panel {
      background: var(--surface); border: 1px solid var(--line); border-radius: 16px;
      padding: 22px; display: flex; flex-direction: column; gap: 14px; min-width: 0;
    }
    .panel h2 { font-family: var(--body); font-size: 18px; font-weight: 700; }
    .panel p { margin: 0; }
    .sec-row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
    .weak { font-weight: 400; font-size: 13px; }
    .chapter {
      border: 1px solid var(--line); border-radius: 12px; padding: 14px;
      display: flex; flex-direction: column; gap: 10px; min-width: 0;
    }
    .ch-top { display: flex; gap: 12px; align-items: flex-end; min-width: 0; }
    .grow { flex: 1 1 auto; min-width: 0; }
    .num {
      flex: 0 0 auto; width: 34px; height: 34px; margin-bottom: 6px; border-radius: 999px;
      background: var(--green); color: var(--on-accent); display: grid; place-items: center; font-weight: 700;
    }
    .ch-bottom { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between; }
    .ch-tools { display: flex; gap: 6px; }
    .tool {
      width: 44px; height: 44px; border: 1px solid var(--line); border-radius: 12px;
      background: var(--surface); color: var(--ink);
    }
    .tool:disabled { opacity: .4; }
    .del { color: var(--brick-ink); }
    .check { display: flex; align-items: center; gap: 10px; font-size: 15px; }
    .cover { display: flex; flex-direction: column; gap: 10px; align-items: flex-start; }
    .cover img { border-radius: 12px; max-height: 240px; max-width: 100%; }
    .upload {
      position: relative; border: 1.5px dashed var(--line); border-radius: 12px; padding: 24px; text-align: center;
      color: var(--muted); font-weight: 600; cursor: pointer; min-height: 44px;
    }
    .hidden-file { position: absolute; width: 1px; height: 1px; min-height: 0; padding: 0; opacity: 0; }
    .upload:focus-within { outline: 2px solid var(--brick); outline-offset: 2px; }
    .btn-sm { min-height: 44px; }
  `]
})
export class BookEditPage implements OnInit {
  private api = inject(ApiService);
  private shop = inject(CommerceService);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private toasts = inject(ToastService);

  readonly id = signal<number | null>(null);
  readonly saving = signal(false);
  readonly uploading = signal(false);
  readonly cover = signal<CoverRef | null>(null);
  readonly savedPublished = signal(false);
  readonly chapters = signal<ChapterRow[]>([emptyChapter()]);

  title = '';
  slug = '';
  authorName = '';
  year: number | null = null;
  country = '';
  lead = '';
  description = '';
  price: number | null = 0;
  inSubscription = false;
  isPublished = false;

  ngOnInit(): void {
    const param = this.route.snapshot.paramMap.get('id');
    if (param && param !== 'new') {
      this.id.set(Number(param));
      this.shop.adminBook(Number(param)).subscribe({
        next: (res) => {
          const b = res.data;
          this.title = b.title;
          this.slug = b.slug;
          this.authorName = b.author_name ?? '';
          this.year = b.year ?? null;
          this.country = b.country ?? '';
          this.lead = b.lead ?? '';
          this.description = b.description ?? '';
          this.price = b.price / 100;
          this.inSubscription = b.in_subscription;
          this.isPublished = b.is_published;
          this.savedPublished.set(b.is_published);
          this.cover.set(b.cover && b.cover_media_id ? { id: b.cover_media_id, url: b.cover.url, alt: b.cover.alt } : null);
          this.chapters.set((b.chapters || []).map((c) => ({ title: c.title, body: c.body ?? '', is_preview: !!c.is_preview })));
        },
        error: (err) => this.toasts.error(errorText(err, 'Книга не найдена или нет доступа.')),
      });
    }
  }

  addChapter(): void {
    this.chapters.update((list) => [...list, emptyChapter()]);
  }

  removeChapter(i: number): void {
    this.chapters.update((list) => list.filter((_, k) => k !== i));
  }

  moveChapter(i: number, delta: -1 | 1): void {
    this.chapters.update((list) => {
      const j = i + delta;
      if (j < 0 || j >= list.length) return list;
      const next = [...list];
      [next[i], next[j]] = [next[j], next[i]];
      return next;
    });
  }

  uploadCover(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;

    this.uploading.set(true);
    this.api.uploadMedia(file, this.title).subscribe({
      next: (res) => {
        this.cover.set({ id: res.data.id, url: res.data.url, alt: res.data.alt });
        this.uploading.set(false);
      },
      error: (err) => {
        this.uploading.set(false);
        this.toasts.error(errorText(err));
      },
    });
  }

  save(): void {
    if (!this.title.trim()) {
      this.toasts.error('Укажите название');
      return;
    }
    const price = Math.round(Number(this.price || 0) * 100);
    if (!Number.isFinite(price) || price < 0) {
      this.toasts.error('Цена не может быть отрицательной');
      return;
    }
    // Пустые строки-заготовки не отправляем; главу без названия, но с текстом — считаем ошибкой.
    const rows = this.chapters().filter((c) => c.title.trim() || c.body.trim());
    if (rows.some((c) => !c.title.trim())) {
      this.toasts.error('У каждой главы должно быть название');
      return;
    }

    this.saving.set(true);
    const id = this.id() ?? undefined;
    this.shop.saveBook({
      title: this.title.trim(),
      slug: this.slug.trim() || undefined,
      author_name: this.authorName.trim() || null,
      year: this.year ? Math.round(Number(this.year)) : null,
      country: this.country.trim() || null,
      lead: this.lead.trim() || null,
      description: this.description.trim() || null,
      price,
      in_subscription: this.inSubscription,
      cover_media_id: this.cover()?.id ?? null,
      is_published: this.isPublished,
      chapters: rows.map((c) => ({ title: c.title.trim(), body: c.body, is_preview: c.is_preview })),
    }, id).subscribe({
      next: (res) => {
        this.saving.set(false);
        this.toasts.show('Сохранено');
        this.slug = res.data.slug ?? this.slug;
        this.savedPublished.set(res.data.is_published ?? this.isPublished);
        if (!id) {
          this.id.set(res.data.id);
          this.router.navigate(['/books', res.data.id], { replaceUrl: true });
        }
      },
      error: (err) => {
        this.saving.set(false);
        this.toasts.error(errorText(err));
      },
    });
  }
}
