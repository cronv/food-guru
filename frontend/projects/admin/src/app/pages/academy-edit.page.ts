import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';

import { ApiService } from '@shared/services/api.service';
import { SITE_URL } from '@shared/tokens';
import {
  ACADEMY_FORMAT_LABELS, ACADEMY_LEVEL_LABELS, AcademyFormat, AcademyLevel,
  AcademySession, Media, PUBLISH_STATUS_LABELS, PublishStatus,
} from '@shared/models/api.models';
import { errorText, plural } from '@shared/utils';
import { ToastService } from '../services/toast.service';

type MoveAction = 'publish' | 'archive' | 'draft' | 'moderation';

interface MoveButton {
  action: MoveAction;
  label: string;
  /** Необратимый переход — спрашиваем подтверждение. */
  confirm?: string;
  accent?: boolean;
}

/** Те же правила переходов, что и у конкурсов: показываем только допустимое. */
const MOVES: Record<PublishStatus, MoveButton[]> = {
  draft: [
    { action: 'moderation', label: 'Отправить на проверку' },
    { action: 'publish', label: 'Опубликовать', accent: true },
    { action: 'archive', label: 'В архив', confirm: 'Занятие уйдёт в архив и исчезнёт из списка на сайте.' },
  ],
  moderation: [
    { action: 'publish', label: 'Опубликовать', accent: true },
    { action: 'draft', label: 'Вернуть в черновик' },
    { action: 'archive', label: 'В архив', confirm: 'Занятие уйдёт в архив и исчезнёт из списка на сайте.' },
  ],
  published: [
    { action: 'archive', label: 'В архив', confirm: 'Занятие уйдёт в архив, запись на него закроется.' },
    { action: 'draft', label: 'Вернуть в черновик' },
  ],
  archived: [
    { action: 'draft', label: 'Вернуть в черновик' },
    { action: 'publish', label: 'Опубликовать', accent: true },
  ],
};

/** ISO из ответа API → значение для <input type="datetime-local">. */
function toLocalInput(iso: string | null | undefined): string {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
    + `T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** Значение поля → ISO для бэкенда. Пустая строка означает «дата не задана». */
function fromLocalInput(value: string): string | null {
  if (!value) return null;
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? null : d.toISOString();
}

@Component({
    selector: 'adm-academy-edit',
    imports: [RouterLink, FormsModule],
    template: `
    <nav class="crumbs muted">
      <a routerLink="/academy">Академия</a> / <span>{{ title || 'Новое занятие' }}</span>
    </nav>

    <header class="head">
      <div class="row ident">
        <h1>{{ title || 'Новое занятие' }}</h1>
        @if (status()) {
          <span class="chip" [class]="chipClass()">{{ statusLabel() }}</span>
        }
        @if (isLiveNow()) { <span class="chip chip-b">Идёт сейчас</span> }
      </div>

      <div class="row">
        @if (id() && status() === 'published') {
          <a class="btn btn-ghost" [href]="siteUrl + '/academy/' + slug" target="_blank" rel="noopener">
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

    @if (id()) {
      <div class="note">
        Записались {{ registrations() }}
        {{ plural(registrations(), 'человек', 'человека', 'человек') }}.
        <a class="inline" [routerLink]="['/academy', id(), 'registrations']">Открыть список →</a>
      </div>
    }

    <div class="layout">
      <div class="main">
        <section class="panel">
          <h2>Основное</h2>

          <label class="fl" for="a-title">Название
            <input id="a-title" type="text" name="a-title" [(ngModel)]="title"
                   placeholder="Например, тесто для пиццы без миксера">
          </label>

          <label class="fl" for="a-slug">Адрес
            <input id="a-slug" type="text" name="a-slug" [(ngModel)]="slug" placeholder="testo-dlya-piccy">
            <span class="muted weak">{{ siteUrl }}/academy/{{ slug || '…' }}</span>
          </label>

          <label class="fl" for="a-lead">Короткий лид
            <textarea id="a-lead" name="a-lead" rows="2" [(ngModel)]="lead"
                      placeholder="Пара предложений для карточки занятия"></textarea>
          </label>

          <label class="fl" for="a-desc">Описание
            <textarea id="a-desc" name="a-desc" rows="5" [(ngModel)]="description"
                      placeholder="О чём занятие и для кого оно"></textarea>
          </label>

          <label class="fl" for="a-take">Чему научатся
            <textarea id="a-take" name="a-take" rows="5" [(ngModel)]="takeawaysText"
                      placeholder="Замешивать тесто руками&#10;Понимать, когда оно готово"></textarea>
            <span class="muted weak">Одна строка — один пункт списка.</span>
          </label>
        </section>

        <section class="panel">
          <h2>Время и ссылки</h2>

          @if (format === 'lesson') {
            <p class="note">
              Видеоурок доступен в любое время: дату старта указывать не нужно,
              но без ссылки на запись его не опубликовать.
            </p>
          }

          <div class="two">
            @if (format !== 'lesson') {
              <label class="fl" for="a-start">Старт
                <input id="a-start" type="datetime-local" name="a-start" [(ngModel)]="startsAt">
              </label>
            }
            <label class="fl" for="a-dur">Длительность, мин
              <input id="a-dur" type="number" name="a-dur" min="1" [(ngModel)]="durationMinutes">
            </label>
          </div>

          <label class="fl" for="a-stream">Ссылка на эфир
            <input id="a-stream" type="text" name="a-stream" [(ngModel)]="streamUrl"
                   placeholder="https://…">
            <span class="muted weak">Нужна вебинару и прямому эфиру. Видят её только записавшиеся.</span>
          </label>

          <label class="fl" for="a-record">Ссылка на запись
            <input id="a-record" type="text" name="a-record" [(ngModel)]="recordUrl"
                   placeholder="https://…">
            <span class="muted weak">Для видеоурока обязательна, у эфиров появляется после трансляции.</span>
          </label>
        </section>
      </div>

      <aside class="side">
        <section class="panel">
          <h2>Формат</h2>

          <label class="fl" for="a-format">Формат занятия
            <select id="a-format" name="a-format" [(ngModel)]="format">
              @for (f of formatOptions; track f.value) {
                <option [value]="f.value">{{ f.label }}</option>
              }
            </select>
          </label>

          <label class="fl" for="a-level">Уровень
            <select id="a-level" name="a-level" [(ngModel)]="level">
              @for (l of levelOptions; track l.value) {
                <option [value]="l.value">{{ l.label }}</option>
              }
            </select>
          </label>
        </section>

        <section class="panel">
          <h2>Ведущий</h2>
          <label class="fl" for="a-host">Имя
            <input id="a-host" type="text" name="a-host" [(ngModel)]="hostName" placeholder="Мария Орлова">
          </label>
          <label class="fl" for="a-hosttitle">Должность
            <input id="a-hosttitle" type="text" name="a-hosttitle" [(ngModel)]="hostTitle"
                   placeholder="шеф-кондитер">
          </label>
        </section>

        <section class="panel">
          <h2>Обложка</h2>
          @if (cover(); as c) {
            <div class="cover">
              <img [src]="c.url" [alt]="c.alt || 'Обложка занятия'">
              <button class="btn btn-ghost btn-sm" type="button" (click)="cover.set(null)">Убрать</button>
            </div>
          } @else {
            <label class="upload">
              {{ uploading() ? 'Загружаем…' : '+ Загрузить обложку' }}
              <input type="file" accept="image/*" hidden (change)="uploadCover($event)">
            </label>
          }
          <p class="muted weak">JPEG, PNG или WebP до 8 МБ.</p>
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
    .two { display: flex; flex-wrap: wrap; gap: 14px; }
    .two label { flex: 1 1 200px; min-width: 0; }
    .note .inline { font-weight: 700; text-decoration: underline; }
    .cover { display: flex; flex-direction: column; gap: 10px; align-items: flex-start; }
    .cover img { border-radius: 12px; max-height: 200px; }
    .upload {
      border: 1.5px dashed var(--line); border-radius: 12px; padding: 24px; text-align: center;
      color: var(--muted); font-weight: 600; cursor: pointer; min-height: 44px;
    }
    input[type="datetime-local"] {
      min-height: 46px; padding: 0 14px; border: 1px solid var(--line); border-radius: 12px;
      background: var(--sunken); color: var(--ink); font: inherit; font-weight: 400; width: 100%;
    }
    .modal {
      position: fixed; inset: 0; z-index: 50; background: rgba(12, 11, 8, .6);
      display: grid; place-items: center; padding: 20px;
    }
    .box {
      background: var(--surface); border-radius: 18px; padding: 24px;
      width: min(520px, 100%); display: flex; flex-direction: column; gap: 14px;
    }
    .box h2 { font-size: 22px; }
    .end { justify-content: flex-end; }
  `]
})
export class AcademyEditPage implements OnInit {
  private api = inject(ApiService);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private toasts = inject(ToastService);
  siteUrl = inject(SITE_URL);

  readonly formatOptions = (Object.keys(ACADEMY_FORMAT_LABELS) as AcademyFormat[]).map((value) => ({
    value,
    label: ACADEMY_FORMAT_LABELS[value],
  }));

  readonly levelOptions = (Object.keys(ACADEMY_LEVEL_LABELS) as AcademyLevel[]).map((value) => ({
    value,
    label: ACADEMY_LEVEL_LABELS[value],
  }));

  readonly id = signal<number | null>(null);
  readonly status = signal<PublishStatus | null>(null);
  readonly note = signal<string | null>(null);
  readonly isLiveNow = signal(false);
  readonly registrations = signal(0);
  readonly saving = signal(false);
  readonly moving = signal(false);
  readonly uploading = signal(false);
  readonly cover = signal<Media | null>(null);
  readonly pending = signal<MoveButton | null>(null);

  readonly plural = plural;

  title = '';
  slug = '';
  lead = '';
  description = '';
  takeawaysText = '';
  format: AcademyFormat = 'webinar';
  level: AcademyLevel = 'basic';
  hostName = '';
  hostTitle = '';
  startsAt = '';
  durationMinutes: number | null = null;
  streamUrl = '';
  recordUrl = '';

  ngOnInit(): void {
    const param = this.route.snapshot.paramMap.get('id');
    if (param && param !== 'new') {
      this.id.set(Number(param));
      this.load(Number(param));
    }
  }

  private load(id: number): void {
    this.api.adminAcademySession(id).subscribe({
      next: (res) => this.fill(res.data),
      error: (err) => this.toasts.error(errorText(err, 'Занятие не найдено или нет доступа.')),
    });
  }

  private fill(s: AcademySession): void {
    this.title = s.title;
    this.slug = s.slug;
    this.lead = s.lead ?? '';
    this.description = s.description ?? '';
    this.takeawaysText = (s.takeaways || []).join('\n');
    this.format = s.format;
    this.level = s.level;
    this.hostName = s.host_name ?? '';
    this.hostTitle = s.host_title ?? '';
    this.startsAt = toLocalInput(s.starts_at);
    this.durationMinutes = s.duration_minutes;
    this.streamUrl = s.stream_url ?? '';
    this.recordUrl = s.record_url ?? '';

    this.status.set(s.status);
    this.note.set(s.moderation_note ?? null);
    this.isLiveNow.set(s.is_live_now);
    this.registrations.set(s.registrations_count);
    this.cover.set(s.cover ?? null);
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

  moves(): MoveButton[] {
    const s = this.status();
    // Непосохранённому занятию статус менять нечему.
    return this.id() && s ? MOVES[s] : [];
  }

  uploadCover(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;

    this.uploading.set(true);
    this.api.uploadMedia(file, this.title).subscribe({
      next: (res) => {
        this.cover.set(res.data);
        this.uploading.set(false);
      },
      error: (err) => {
        this.uploading.set(false);
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
      takeaways: this.takeawaysText.split('\n').map((line) => line.trim()).filter(Boolean),
      format: this.format,
      level: this.level,
      host_name: this.hostName.trim() || null,
      host_title: this.hostTitle.trim() || null,
      // У видеоурока даты старта нет — отправляем null, чтобы снять старую.
      starts_at: this.format === 'lesson' ? null : fromLocalInput(this.startsAt),
      duration_minutes: this.durationMinutes,
      stream_url: this.streamUrl.trim() || null,
      record_url: this.recordUrl.trim() || null,
      cover_media_id: this.cover()?.id ?? null,
    };
  }

  save(): void {
    if (!this.title.trim()) {
      this.toasts.error('Укажите название');
      return;
    }

    this.saving.set(true);
    const id = this.id();
    const request = id
      ? this.api.updateAcademySession(id, this.payload())
      : this.api.createAcademySession(this.payload());

    request.subscribe({
      next: (res) => {
        this.saving.set(false);
        this.toasts.show('Сохранено');
        this.fill(res.data);

        if (!id) {
          this.id.set(res.data.id);
          this.router.navigate(['/academy', res.data.id], { replaceUrl: true });
        }
      },
      error: (err) => {
        this.saving.set(false);
        // Полноту занятия проверяет бэкенд — его текст точнее нашего.
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
    this.api.moveAcademySession(id, action).subscribe({
      next: (res) => {
        this.moving.set(false);
        this.status.set(res.data.status as PublishStatus);
        this.note.set(null);
        this.toasts.show(`Статус: ${res.data.status_label}`);
        this.load(id);
      },
      error: (err) => {
        this.moving.set(false);
        this.toasts.error(errorText(err));
      },
    });
  }
}
