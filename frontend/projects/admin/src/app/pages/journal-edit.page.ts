import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';

import { ApiService } from '@shared/services/api.service';
import { SITE_URL } from '@shared/tokens';
import {
  ApiError, JOURNAL_PERIOD_LABELS, JournalIssue, JournalPeriod, Media,
  PUBLISH_STATUS_LABELS, PublishStatus,
} from '@shared/models/api.models';
import { errorText, formatDate, pluralize } from '@shared/utils';
import { ToastService } from '../services/toast.service';

type MoveAction = 'publish' | 'archive' | 'draft' | 'moderation';

interface MoveButton {
  action: MoveAction;
  label: string;
  /** Необратимый переход — спрашиваем подтверждение. */
  confirm?: string;
  accent?: boolean;
}

/** Те же правила переходов, что у конкурсов и академии: показываем только допустимое. */
const MOVES: Record<PublishStatus, MoveButton[]> = {
  draft: [
    { action: 'moderation', label: 'На проверку' },
    { action: 'publish', label: 'Опубликовать', accent: true },
    { action: 'archive', label: 'В архив', confirm: 'Выпуск уйдёт в архив и исчезнёт из списка на сайте.' },
  ],
  moderation: [
    { action: 'publish', label: 'Опубликовать', accent: true },
    { action: 'draft', label: 'Вернуть в черновик' },
    { action: 'archive', label: 'В архив', confirm: 'Выпуск уйдёт в архив и исчезнёт из списка на сайте.' },
  ],
  published: [
    { action: 'archive', label: 'В архив', confirm: 'Выпуск уйдёт в архив, скачать PDF с сайта станет нельзя.' },
    { action: 'draft', label: 'Вернуть в черновик' },
  ],
  archived: [
    { action: 'draft', label: 'Вернуть в черновик' },
    { action: 'publish', label: 'Опубликовать', accent: true },
  ],
};

/** Предел на стороне сервера — повторяем его в подсказке у поля. */
const MAX_FILE_MB = 50;

/**
 * Дата выпуска из ответа API → значение для <input type="date">.
 * Готовую дату «2026-01-12» берём как есть: прогон через Date сдвинул бы её
 * на сутки в часовых поясах западнее Гринвича.
 */
function toDateInput(value: string | null | undefined): string {
  if (!value) return '';
  const ready = /^\d{4}-\d{2}-\d{2}/.exec(value);
  if (ready) return ready[0];

  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return '';
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/** 2,4 МБ — размер приходит в байтах. */
function sizeLabel(bytes: number | null | undefined): string {
  if (!bytes) return '';
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} КБ`;
  return `${(bytes / (1024 * 1024)).toFixed(1).replace('.', ',')} МБ`;
}

@Component({
    selector: 'adm-journal-edit',
    imports: [RouterLink, FormsModule],
    template: `
    <nav class="crumbs muted">
      <a routerLink="/journal">Журнал</a> / <span>{{ title || 'Новый выпуск' }}</span>
    </nav>

    <header class="head">
      <div class="row ident">
        <h1>{{ title || 'Новый выпуск' }}</h1>
        @if (status()) {
          <span class="chip" [class]="chipClass()">{{ statusLabel() }}</span>
        }
        @if (periodRange()) { <span class="chip">{{ periodRange() }}</span> }
      </div>

      <div class="row">
        @if (id() && status() === 'published') {
          <a class="btn btn-ghost" [href]="siteUrl + '/journal/' + slug" target="_blank" rel="noopener">
            Посмотреть на сайте ↗
          </a>
        }
        <button class="btn btn-ghost" type="button" [disabled]="saving()" (click)="save()">
          {{ saving() ? 'Сохраняем…' : 'Сохранить' }}
        </button>
        @for (m of moves(); track m.action) {
          <button class="btn" [class.btn-green]="m.accent" [class.btn-ghost]="!m.accent"
                  type="button" [disabled]="moving()" (click)="askMove(m)">
            {{ m.label }}
          </button>
        }
      </div>
    </header>

    @if (note()) {
      <div class="note note-warn"><strong>Комментарий проверяющего:</strong> {{ note() }}</div>
    }

    @if (id() && !hasFile()) {
      <div class="note note-warn">
        У выпуска нет PDF. Пока файл не загружен, опубликовать выпуск нельзя.
      </div>
    }

    @if (id() && hasFile()) {
      <div class="note">
        Скачали {{ pluralize(downloads(), 'раз', 'раза', 'раз') }}.
        @if (publishedAt()) { Опубликован {{ formatDate(publishedAt()) }}. }
      </div>
    }

    <div class="layout">
      <div class="main">
        <section class="panel">
          <h2>Основное</h2>

          <label class="fl" for="j-title">Заголовок
            <input id="j-title" type="text" name="j-title" [(ngModel)]="title"
                   placeholder="Например, заготовки на зиму без стерилизации">
          </label>

          <label class="fl" for="j-slug">Адрес
            <input id="j-slug" type="text" name="j-slug" [(ngModel)]="slug" placeholder="zagotovki-na-zimu">
            <span class="muted weak">{{ siteUrl }}/journal/{{ slug || '…' }}</span>
          </label>

          <label class="fl" for="j-lead">Краткое описание
            <textarea id="j-lead" name="j-lead" rows="2" [(ngModel)]="lead"
                      placeholder="Пара предложений для карточки выпуска"></textarea>
          </label>

          <label class="fl" for="j-desc">Полное описание
            <textarea id="j-desc" name="j-desc" rows="5" [(ngModel)]="description"
                      placeholder="Что внутри выпуска и кому он пригодится"></textarea>
          </label>
        </section>

        <section class="panel">
          <h2>Файл выпуска</h2>

          @if (!id()) {
            <p class="note">
              Сначала сохраните выпуск: PDF прикрепляется к уже существующей записи.
            </p>
          } @else if (hasFile()) {
            <dl class="facts">
              <div>
                <dt class="muted">Имя файла</dt>
                <dd>{{ fileName() || 'PDF без имени' }}</dd>
              </div>
              <div>
                <dt class="muted">Размер</dt>
                <dd class="tnum">{{ sizeLabel(fileSize()) || '—' }}</dd>
              </div>
              <div>
                <dt class="muted">Объём</dt>
                <dd class="tnum">{{ pagesLabel() || '—' }}</dd>
              </div>
            </dl>

            <div class="row">
              <label class="btn btn-ghost btn-sm pick">
                {{ uploadingFile() ? 'Загружаем…' : 'Заменить файл' }}
                <input class="hidden-file" type="file" accept="application/pdf"
                       [disabled]="uploadingFile()" (change)="uploadFile($event)">
              </label>
              <button class="btn btn-ghost btn-sm" type="button" [disabled]="deletingFile()"
                      (click)="confirmFileDelete.set(true)">
                Удалить файл
              </button>
            </div>
          } @else {
            <label class="upload">
              {{ uploadingFile() ? 'Загружаем…' : '+ Загрузить PDF' }}
              <input class="hidden-file" type="file" accept="application/pdf"
                     [disabled]="uploadingFile()" (change)="uploadFile($event)">
            </label>
          }

          <p class="muted weak">
            Только PDF, не больше {{ maxFileMb }} МБ. Число страниц и размер сервер
            считает сам после загрузки.
          </p>
        </section>
      </div>

      <aside class="side">
        <section class="panel">
          <h2>Тип и срок</h2>

          <label class="fl" for="j-period">Тип выпуска
            <select id="j-period" name="j-period" [(ngModel)]="period">
              @for (p of periodOptions; track p.value) {
                <option [value]="p.value">{{ p.label }}</option>
              }
            </select>
          </label>

          <label class="fl" for="j-date">Дата выпуска
            <input id="j-date" type="date" name="j-date" [(ngModel)]="issueDate">
            <span class="muted weak">
              Сервер сам приводит дату к началу периода: у гайда недели — к понедельнику,
              у гайда месяца — к первому числу. Выбранное 15 января станет 1 января,
              это не ошибка.
            </span>
          </label>

          @if (periodRange()) {
            <p class="muted weak">Текущий срок выпуска: {{ periodRange() }}.</p>
          }
        </section>

        <section class="panel">
          <h2>Обложка</h2>
          @if (cover(); as c) {
            <div class="cover">
              <img [src]="c.url" [alt]="c.alt || 'Обложка выпуска'">
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

          @if (id() && hasFile()) {
            <button class="btn btn-ghost btn-sm" type="button" [disabled]="rendering()"
                    (click)="renderCover()">
              {{ rendering() ? 'Рисуем…' : 'Нарисовать из первой страницы PDF' }}
            </button>
            <p class="muted weak">
              При первой загрузке файла сервер рисует обложку сам, если её ещё нет.
              Кнопка нужна, чтобы перерисовать обложку после замены PDF.
            </p>
          }
        </section>
      </aside>
    </div>

    @if (pending(); as m) {
      <div class="modal" role="dialog" aria-modal="true" aria-label="Подтверждение">
        <div class="box">
          <h2>{{ m.label }}</h2>
          <p class="muted">{{ m.confirm }}</p>
          <div class="row end">
            <button class="btn btn-ghost btn-sm" type="button" (click)="pending.set(null)">Отмена</button>
            <button class="btn btn-primary btn-sm" type="button" (click)="move(m.action)">{{ m.label }}</button>
          </div>
        </div>
      </div>
    }

    @if (confirmFileDelete()) {
      <div class="modal" role="dialog" aria-modal="true" aria-label="Удалить файл выпуска">
        <div class="box">
          <h2>Удалить файл</h2>
          <p class="muted">
            PDF выпуска удалится, вместе с ним пропадут число страниц и размер.
            Опубликованный выпуск без файла скачать будет нельзя.
          </p>
          <div class="row end">
            <button class="btn btn-ghost btn-sm" type="button"
                    (click)="confirmFileDelete.set(false)">Отмена</button>
            <button class="btn btn-primary btn-sm" type="button" (click)="deleteFile()">Удалить файл</button>
          </div>
        </div>
      </div>
    }
  `,
    styles: [`
    .crumbs { font-size: 13px; margin-bottom: 12px; }
    .head { display: flex; flex-wrap: wrap; gap: 16px; align-items: center; justify-content: space-between; margin-bottom: 20px; }
    .ident { align-items: center; gap: 14px; }
    h1 { font-size: 28px; }
    .layout { display: flex; flex-wrap: wrap; gap: 22px; align-items: flex-start; }
    .main { flex: 999 1 520px; min-width: 0; display: flex; flex-direction: column; gap: 20px; }
    .side { flex: 1 1 300px; min-width: 0; display: flex; flex-direction: column; gap: 20px; }
    .panel {
      background: var(--surface); border: 1px solid var(--line); border-radius: 16px;
      padding: 22px; display: flex; flex-direction: column; gap: 14px;
    }
    .panel h2 { font-family: var(--body); font-size: 18px; font-weight: 700; }
    .panel p { margin: 0; }
    .weak { font-weight: 400; font-size: 13px; }
    .facts { margin: 0; display: flex; flex-direction: column; gap: 10px; }
    .facts div { display: flex; flex-wrap: wrap; gap: 4px 10px; justify-content: space-between; }
    .facts dt { font-size: 13px; }
    .facts dd { margin: 0; font-size: 14px; font-weight: 600; min-width: 0; overflow-wrap: anywhere; }
    .cover { display: flex; flex-direction: column; gap: 10px; align-items: flex-start; }
    .cover img { border-radius: 12px; max-height: 200px; }
    .upload {
      border: 1.5px dashed var(--line); border-radius: 12px; padding: 24px; text-align: center;
      color: var(--muted); font-weight: 600; cursor: pointer; min-height: 44px;
    }
    /* Выбор файла — тот же <label>, но в виде обычной кнопки рядом с «Удалить». */
    .pick { cursor: pointer; }
    .upload, .pick { position: relative; }
    /* Само поле прячем не атрибутом hidden: скрытое так поле выпадает из
       порядка табуляции, и до загрузки файла нельзя добраться с клавиатуры. */
    .hidden-file { position: absolute; width: 1px; height: 1px; min-height: 0; padding: 0; opacity: 0; }
    .upload:focus-within, .pick:focus-within { outline: 2px solid var(--brick); outline-offset: 2px; }
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
    .end { justify-content: flex-end; }
    /* Цели нажатия держим в 44px: мелкие кнопки неудобны на телефоне. */
    .btn-sm { min-height: 44px; }
  `]
})
export class JournalEditPage implements OnInit {
  private api = inject(ApiService);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private toasts = inject(ToastService);
  siteUrl = inject(SITE_URL);

  readonly periodOptions = (Object.keys(JOURNAL_PERIOD_LABELS) as JournalPeriod[]).map((value) => ({
    value,
    label: JOURNAL_PERIOD_LABELS[value],
  }));

  readonly maxFileMb = MAX_FILE_MB;

  readonly id = signal<number | null>(null);
  readonly status = signal<PublishStatus | null>(null);
  readonly note = signal<string | null>(null);
  readonly periodRange = signal('');
  readonly publishedAt = signal<string | null>(null);
  readonly downloads = signal(0);
  readonly hasFile = signal(false);
  readonly fileName = signal<string | null>(null);
  readonly fileSize = signal<number | null>(null);
  readonly pages = signal<number | null>(null);
  readonly saving = signal(false);
  readonly moving = signal(false);
  readonly uploadingFile = signal(false);
  readonly deletingFile = signal(false);
  readonly uploadingCover = signal(false);
  readonly rendering = signal(false);
  readonly cover = signal<Media | null>(null);
  readonly pending = signal<MoveButton | null>(null);
  readonly confirmFileDelete = signal(false);

  readonly formatDate = formatDate;
  readonly pluralize = pluralize;
  readonly sizeLabel = sizeLabel;

  title = '';
  slug = '';
  lead = '';
  description = '';
  period: JournalPeriod = 'week';
  issueDate = '';

  ngOnInit(): void {
    const param = this.route.snapshot.paramMap.get('id');
    if (param && param !== 'new') {
      this.id.set(Number(param));
      this.load(Number(param));
    }
  }

  private load(id: number): void {
    this.api.adminJournalIssue(id).subscribe({
      next: (res) => this.fill(res.data),
      error: (err) => this.toasts.error(errorText(err, 'Выпуск не найден или нет доступа.')),
    });
  }

  private fill(i: JournalIssue): void {
    this.title = i.title;
    this.slug = i.slug;
    this.lead = i.lead ?? '';
    this.description = i.description ?? '';
    this.period = i.period;
    this.issueDate = toDateInput(i.issue_date);

    this.status.set(i.status);
    this.note.set(i.moderation_note ?? null);
    this.periodRange.set(i.period_range);
    this.publishedAt.set(i.published_at);
    this.downloads.set(i.downloads_count);
    this.hasFile.set(i.has_file);
    this.fileName.set(i.file_name ?? null);
    this.fileSize.set(i.file_size);
    this.pages.set(i.pages);
    this.cover.set(i.cover ?? null);
  }

  statusLabel(): string {
    const s = this.status();
    return s ? PUBLISH_STATUS_LABELS[s] : '';
  }

  chipClass(): string {
    const s = this.status();
    if (s === 'published') return 'chip-g';
    if (s === 'moderation') return 'chip-b';
    return '';
  }

  pagesLabel(): string {
    const p = this.pages();
    if (!p) return '';
    return pluralize(p, 'страница', 'страницы', 'страниц');
  }

  moves(): MoveButton[] {
    const s = this.status();
    // Непосохранённому выпуску статус менять нечему.
    return this.id() && s ? MOVES[s] : [];
  }

  uploadCover(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;

    this.uploadingCover.set(true);
    this.api.uploadMedia(file, this.title).subscribe({
      next: (res) => {
        this.cover.set(res.data);
        this.uploadingCover.set(false);
      },
      error: (err) => {
        this.uploadingCover.set(false);
        this.toasts.error(errorText(err));
      },
    });
  }

  /** PDF прикрепляется к сохранённому выпуску: без id серверу некуда его положить. */
  uploadFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';

    const id = this.id();
    if (!file || !id) return;

    this.uploadingFile.set(true);
    this.api.uploadJournalFile(id, file).subscribe({
      next: (res) => {
        this.uploadingFile.set(false);
        this.fill(res.data);
        this.toasts.show('Файл загружен');
      },
      error: (err) => {
        this.uploadingFile.set(false);
        // Размер и тип файла проверяет сервер — его текст точнее нашего.
        this.toasts.error(errorText(err));
      },
    });
  }

  deleteFile(): void {
    const id = this.id();
    if (!id) return;

    this.confirmFileDelete.set(false);
    this.deletingFile.set(true);
    this.api.deleteJournalFile(id).subscribe({
      next: (res) => {
        this.deletingFile.set(false);
        this.fill(res.data);
        this.toasts.show('Файл удалён');
      },
      error: (err) => {
        this.deletingFile.set(false);
        this.toasts.error(errorText(err));
      },
    });
  }

  renderCover(): void {
    const id = this.id();
    if (!id) return;

    this.rendering.set(true);
    this.api.renderJournalCover(id).subscribe({
      next: (res) => {
        this.rendering.set(false);
        this.fill(res.data);
        this.toasts.show('Обложка перерисована');
      },
      error: (err) => {
        this.rendering.set(false);
        // render_failed — ожидаемый ответ 422: из PDF не удалось снять первую
        // страницу. Это не сбой админки, поэтому показываем спокойным сообщением.
        const code = (err as { error?: ApiError }).error?.code;
        if (code === 'render_failed') {
          this.toasts.show(errorText(err));
          return;
        }
        this.toasts.error(errorText(err));
      },
    });
  }

  private payload(): Record<string, unknown> {
    return {
      title: this.title.trim(),
      slug: this.slug.trim() || undefined,
      lead: this.lead.trim() || null,
      description: this.description.trim() || null,
      period: this.period,
      // Дату отправляем как есть, без перевода в ISO: начало периода считает сервер.
      issue_date: this.issueDate || null,
      cover_media_id: this.cover()?.id ?? null,
    };
  }

  save(): void {
    if (!this.title.trim()) {
      this.toasts.error('Укажите заголовок');
      return;
    }

    this.saving.set(true);
    const id = this.id();
    const request = id
      ? this.api.updateJournalIssue(id, this.payload())
      : this.api.createJournalIssue(this.payload());

    request.subscribe({
      next: (res) => {
        this.saving.set(false);
        this.toasts.show('Сохранено');
        this.fill(res.data);

        if (!id) {
          this.id.set(res.data.id);
          this.router.navigate(['/journal', res.data.id], { replaceUrl: true });
        }
      },
      error: (err) => {
        this.saving.set(false);
        // Полноту выпуска проверяет бэкенд — его текст точнее нашего.
        this.toasts.error(errorText(err));
      },
    });
  }

  askMove(m: MoveButton): void {
    if (m.confirm) {
      this.pending.set(m);
      return;
    }
    this.move(m.action);
  }

  move(action: MoveAction): void {
    const id = this.id();
    if (!id) return;

    this.pending.set(null);
    this.moving.set(true);
    this.api.moveJournalIssue(id, action).subscribe({
      next: (res) => {
        this.moving.set(false);
        this.status.set(res.data.status as PublishStatus);
        this.note.set(null);
        this.toasts.show(`Статус: ${res.data.status_label}`);
        this.load(id);
      },
      error: (err) => {
        this.moving.set(false);
        // Публикацию без PDF сервер отклоняет 422 с понятным текстом — показываем его.
        this.toasts.error(errorText(err));
      },
    });
  }
}
