import { Component, OnInit, WritableSignal, inject, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';

import { ApiService } from '@shared/services/api.service';
import { AdminClubItem, CommerceService } from '@shared/services/commerce.service';
import { ClubKind } from '@shared/models/commerce.models';
import { errorText } from '@shared/utils';
import { ToastService } from '../services/toast.service';

/** Строка-обёртка: ngModel внутри @for не умеет менять примитив массива на месте. */
interface LineRow { text: string }
interface CoverRef { id: number; url: string; alt: string | null }

const KIND_OPTIONS: { value: ClubKind; label: string }[] = [
  { value: 'video', label: 'Видео' },
  { value: 'masterclass', label: 'Мастер-класс' },
  { value: 'recipe', label: 'Рецепт' },
  { value: 'material', label: 'Материал' },
];

/** Предел на стороне сервера — повторяем его в подсказке и проверке. */
const MAX_VIDEO_MB = 500;

const toLines = (list: string[] | undefined | null): LineRow[] => {
  const rows = (list || []).map((text) => ({ text }));
  return rows.length ? rows : [{ text: '' }];
};

@Component({
    selector: 'adm-club-edit',
    imports: [RouterLink, FormsModule],
    template: `
    <nav class="crumbs muted">
      <a routerLink="/club">Закрытая академия</a> / <span>{{ title || 'Новый материал' }}</span>
    </nav>

    <header class="head">
      <div class="row ident">
        <h1>{{ title || 'Новый материал' }}</h1>
        @if (id()) {
          <span class="chip" [class.chip-g]="savedPublished()">{{ savedPublished() ? 'Опубликован' : 'Черновик' }}</span>
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

          <label class="fl" for="c-kind">Тип
            <select id="c-kind" name="c-kind" [(ngModel)]="kind">
              @for (k of kindOptions; track k.value) {
                <option [value]="k.value">{{ k.label }}</option>
              }
            </select>
          </label>

          <label class="fl" for="c-title">Название
            <input id="c-title" type="text" name="c-title" [(ngModel)]="title" placeholder="Например, Соусы французской кухни">
          </label>

          <label class="fl" for="c-slug">Адрес
            <input id="c-slug" type="text" name="c-slug" [(ngModel)]="slug" placeholder="sousy-francuzskoy-kuhni">
            <span class="muted weak">Пустое поле — адрес соберётся из названия.</span>
          </label>

          <div class="two">
            <label class="fl" for="c-chef">Шеф
              <input id="c-chef" type="text" name="c-chef" [(ngModel)]="chefName" placeholder="Имя ведущего">
            </label>
            <label class="fl" for="c-dur">Длительность, мин
              <input id="c-dur" type="number" name="c-dur" min="1" step="1" [(ngModel)]="durationMinutes">
            </label>
          </div>

          <label class="fl" for="c-lead">Краткое описание
            <textarea id="c-lead" name="c-lead" rows="2" [(ngModel)]="lead"
                      placeholder="Пара предложений для карточки"></textarea>
          </label>

          <label class="fl" for="c-body">Текст
            <textarea id="c-body" name="c-body" rows="8" [(ngModel)]="body"
                      placeholder="Описание, конспект или сам материал"></textarea>
          </label>
        </section>

        @if (kind === 'recipe') {
          <section class="panel">
            <div class="sec-row">
              <h2>Ингредиенты</h2>
              <button class="btn btn-ghost btn-sm" type="button" (click)="addLine(ingredients)">+ Строка</button>
            </div>
            @for (row of ingredients(); track $index; let i = $index) {
              <div class="line">
                <input type="text" [(ngModel)]="row.text" [name]="'c-ing-' + i" placeholder="Мука — 200 г"
                       [attr.aria-label]="'Ингредиент ' + (i + 1)">
                <button class="del" type="button" (click)="removeLine(ingredients, i)"
                        [attr.aria-label]="'Удалить ингредиент ' + (i + 1)">✕</button>
              </div>
            }
          </section>

          <section class="panel">
            <div class="sec-row">
              <h2>Шаги</h2>
              <button class="btn btn-ghost btn-sm" type="button" (click)="addLine(steps)">+ Шаг</button>
            </div>
            @for (row of steps(); track $index; let i = $index) {
              <div class="line">
                <span class="num">{{ i + 1 }}</span>
                <textarea rows="2" [(ngModel)]="row.text" [name]="'c-step-' + i" placeholder="Что делать на этом шаге"
                          [attr.aria-label]="'Шаг ' + (i + 1)"></textarea>
                <button class="del" type="button" (click)="removeLine(steps, i)"
                        [attr.aria-label]="'Удалить шаг ' + (i + 1)">✕</button>
              </div>
            }
          </section>
        }

        <section class="panel">
          <h2>Видео</h2>
          @if (!id()) {
            <p class="note">Сначала сохраните материал: видео прикрепляется к уже существующей записи.</p>
          } @else {
            @if (hasVideo()) {
              <p class="state"><span class="chip chip-g">Видео загружено</span></p>
            } @else {
              <p class="muted weak">Видео ещё не загружено.</p>
            }
            <label class="upload">
              {{ uploadingVideo() ? 'Загружаем… Большой файл может идти несколько минут' : (hasVideo() ? 'Заменить видео' : '+ Загрузить видео') }}
              <input class="hidden-file" type="file" accept="video/mp4,video/webm"
                     [disabled]="uploadingVideo()" (change)="uploadVideo($event)">
            </label>
          }
          <p class="muted weak">MP4 или WebM до {{ maxVideoMb }} МБ. Смотреть его смогут только подписчики.</p>
        </section>
      </div>

      <aside class="side">
        <section class="panel">
          <h2>Публикация</h2>
          <label class="check">
            <input type="checkbox" name="c-pub" [(ngModel)]="isPublished"> Опубликовать для подписчиков
          </label>
        </section>

        <section class="panel">
          <h2>Обложка</h2>
          @if (cover(); as c) {
            <div class="cover">
              <img [src]="c.url" [alt]="c.alt || 'Обложка материала'">
              <button class="btn btn-ghost btn-sm" type="button" (click)="cover.set(null)">Убрать</button>
            </div>
          } @else {
            <label class="upload">
              {{ uploadingCover() ? 'Загружаем…' : '+ Загрузить обложку' }}
              <input class="hidden-file" type="file" accept="image/*"
                     [disabled]="uploadingCover()" (change)="uploadCover($event)">
            </label>
          }
          <p class="muted weak">JPEG, PNG или WebP до 8 МБ.</p>
        </section>
      </aside>
    </div>
  `,
    styles: [`
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
    .two { display: flex; flex-wrap: wrap; gap: 12px; }
    .two label { flex: 1 1 140px; min-width: 0; }
    .line { display: flex; gap: 10px; align-items: flex-start; min-width: 0; }
    .line input, .line textarea { flex: 1 1 auto; min-width: 0; }
    .num {
      flex: 0 0 auto; width: 34px; height: 34px; margin-top: 6px; border-radius: 999px;
      background: var(--green); color: var(--on-accent); display: grid; place-items: center; font-weight: 700;
    }
    .del {
      flex: 0 0 auto; width: 46px; height: 46px; border: 1px solid var(--line);
      border-radius: 12px; background: var(--surface); color: var(--brick-ink);
    }
    .check { display: flex; align-items: center; gap: 10px; font-size: 15px; }
    .cover { display: flex; flex-direction: column; gap: 10px; align-items: flex-start; }
    .cover img { border-radius: 12px; max-height: 220px; max-width: 100%; }
    .upload {
      position: relative; border: 1.5px dashed var(--line); border-radius: 12px; padding: 24px; text-align: center;
      color: var(--muted); font-weight: 600; cursor: pointer; min-height: 44px;
    }
    .hidden-file { position: absolute; width: 1px; height: 1px; min-height: 0; padding: 0; opacity: 0; }
    .upload:focus-within { outline: 2px solid var(--brick); outline-offset: 2px; }
    .btn-sm { min-height: 44px; }
  `]
})
export class ClubEditPage implements OnInit {
  private api = inject(ApiService);
  private shop = inject(CommerceService);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private toasts = inject(ToastService);

  readonly kindOptions = KIND_OPTIONS;
  readonly maxVideoMb = MAX_VIDEO_MB;

  readonly id = signal<number | null>(null);
  readonly saving = signal(false);
  readonly uploadingCover = signal(false);
  readonly uploadingVideo = signal(false);
  readonly hasVideo = signal(false);
  readonly savedPublished = signal(false);
  readonly cover = signal<CoverRef | null>(null);
  readonly ingredients = signal<LineRow[]>([{ text: '' }]);
  readonly steps = signal<LineRow[]>([{ text: '' }]);

  kind: ClubKind = 'video';
  title = '';
  slug = '';
  lead = '';
  body = '';
  chefName = '';
  durationMinutes: number | null = null;
  isPublished = false;

  ngOnInit(): void {
    const param = this.route.snapshot.paramMap.get('id');
    if (param && param !== 'new') {
      this.id.set(Number(param));
      this.shop.adminClubItem(Number(param)).subscribe({
        next: (res) => {
          const c = res.data;
          this.fillCard(c);
          this.lead = c.lead ?? '';
          this.body = c.body ?? '';
          this.ingredients.set(toLines(c.data?.ingredients));
          this.steps.set(toLines(c.data?.steps));
        },
        error: (err) => this.toasts.error(errorText(err, 'Материал не найден или нет доступа.')),
      });
    }
  }

  /** Поля, которые есть и в карточке списка, и в ответе на загрузку видео. */
  private fillCard(c: AdminClubItem): void {
    this.kind = c.kind;
    this.title = c.title;
    this.slug = c.slug;
    this.chefName = c.chef_name ?? '';
    this.durationMinutes = c.duration_minutes;
    this.isPublished = c.is_published;
    this.savedPublished.set(c.is_published);
    this.hasVideo.set(c.has_video);
    this.cover.set(c.cover && c.cover_media_id ? { id: c.cover_media_id, url: c.cover.url, alt: c.cover.alt } : null);
  }

  addLine(list: WritableSignal<LineRow[]>): void {
    list.update((rows) => [...rows, { text: '' }]);
  }

  removeLine(list: WritableSignal<LineRow[]>, i: number): void {
    list.update((rows) => rows.filter((_, k) => k !== i));
  }

  uploadCover(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;

    this.uploadingCover.set(true);
    this.api.uploadMedia(file, this.title).subscribe({
      next: (res) => {
        this.cover.set({ id: res.data.id, url: res.data.url, alt: res.data.alt });
        this.uploadingCover.set(false);
      },
      error: (err) => {
        this.uploadingCover.set(false);
        this.toasts.error(errorText(err));
      },
    });
  }

  /** Видео прикрепляется к сохранённому материалу: без id серверу некуда его положить. */
  uploadVideo(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    const id = this.id();
    if (!file || !id) return;

    if (file.size > MAX_VIDEO_MB * 1024 * 1024) {
      this.toasts.error(`Файл больше ${MAX_VIDEO_MB} МБ`);
      return;
    }

    this.uploadingVideo.set(true);
    this.shop.uploadClubVideo(id, file).subscribe({
      next: (res) => {
        this.uploadingVideo.set(false);
        this.hasVideo.set(res.data?.has_video ?? true);
        this.toasts.show('Видео загружено');
      },
      error: (err) => {
        this.uploadingVideo.set(false);
        // Тип и размер файла проверяет сервер — его текст точнее нашего.
        this.toasts.error(errorText(err));
      },
    });
  }

  private lines(list: LineRow[]): string[] {
    return list.map((r) => r.text.trim()).filter(Boolean);
  }

  save(): void {
    if (!this.title.trim()) {
      this.toasts.error('Укажите название');
      return;
    }

    const duration = this.durationMinutes === null || (this.durationMinutes as unknown) === ''
      ? null
      : Math.round(Number(this.durationMinutes));

    this.saving.set(true);
    const id = this.id() ?? undefined;
    this.shop.saveClubItem({
      kind: this.kind,
      title: this.title.trim(),
      slug: this.slug.trim() || undefined,
      lead: this.lead.trim() || null,
      body: this.body.trim() || null,
      chef_name: this.chefName.trim() || null,
      duration_minutes: duration,
      cover_media_id: this.cover()?.id ?? null,
      // Ингредиенты и шаги имеют смысл только у рецепта — у остальных не отправляем мусор.
      data: this.kind === 'recipe'
        ? { ingredients: this.lines(this.ingredients()), steps: this.lines(this.steps()) }
        : null,
      is_published: this.isPublished,
    }, id).subscribe({
      next: (res) => {
        this.saving.set(false);
        this.toasts.show('Сохранено');
        this.slug = res.data.slug ?? this.slug;
        this.savedPublished.set(res.data.is_published ?? this.isPublished);
        if (!id) {
          this.id.set(res.data.id);
          this.router.navigate(['/club', res.data.id], { replaceUrl: true });
        }
      },
      error: (err) => {
        this.saving.set(false);
        this.toasts.error(errorText(err));
      },
    });
  }
}
