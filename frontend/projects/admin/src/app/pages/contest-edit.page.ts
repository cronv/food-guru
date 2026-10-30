import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';

import { ApiService } from '@shared/services/api.service';
import { SITE_URL } from '@shared/tokens';
import {
  CONTEST_KIND_LABELS, Contest, ContestKind, Media,
  PUBLISH_STATUS_LABELS, PublishStatus,
} from '@shared/models/api.models';
import { errorText } from '@shared/utils';
import { ToastService } from '../services/toast.service';

type MoveAction = 'publish' | 'archive' | 'draft' | 'moderation';

interface MoveButton {
  action: MoveAction;
  label: string;
  /** Необратимый переход — спрашиваем подтверждение. */
  confirm?: string;
  accent?: boolean;
}

/**
 * Разрешённые переходы повторяют правила бэкенда: лишние кнопки
 * только провоцировали бы 422, поэтому показываем ровно допустимые.
 */
const MOVES: Record<PublishStatus, MoveButton[]> = {
  draft: [
    { action: 'moderation', label: 'Отправить на проверку' },
    { action: 'publish', label: 'Опубликовать', accent: true },
    { action: 'archive', label: 'В архив', confirm: 'Конкурс уйдёт в архив и исчезнёт из списка на сайте.' },
  ],
  moderation: [
    { action: 'publish', label: 'Опубликовать', accent: true },
    { action: 'draft', label: 'Вернуть в черновик' },
    { action: 'archive', label: 'В архив', confirm: 'Конкурс уйдёт в архив и исчезнёт из списка на сайте.' },
  ],
  published: [
    { action: 'archive', label: 'В архив', confirm: 'Конкурс уйдёт в архив: приём работ и голосование закроются.' },
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
    selector: 'adm-contest-edit',
    imports: [RouterLink, FormsModule],
    template: `
    <nav class="crumbs muted">
      <a routerLink="/contests">Конкурсы</a> / <span>{{ title || 'Новый конкурс' }}</span>
    </nav>

    <header class="head">
      <div class="row ident">
        <h1>{{ title || 'Новый конкурс' }}</h1>
        @if (status()) {
          <span class="chip" [class]="chipClass()">{{ statusLabel() }}</span>
          @if (phaseLabel()) { <span class="chip">{{ phaseLabel() }}</span> }
        }
      </div>

      <div class="row">
        @if (id() && status() === 'published') {
          <a class="btn btn-ghost" [href]="siteUrl + '/contests/' + slug" target="_blank" rel="noopener">
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

    @if (id() && entriesCount()) {
      <div class="note">
        Прислано {{ entriesCount() }}, голосов {{ votesCount() }}.
        <a class="inline" [routerLink]="['/contests', id(), 'entries']">Разобрать работы →</a>
      </div>
    }

    <div class="layout">
      <div class="main">
        <section class="panel">
          <h2>Основное</h2>

          <label class="fl" for="c-title">Название
            <input id="c-title" type="text" name="c-title" [(ngModel)]="title"
                   placeholder="Например, осенний пирог года">
          </label>

          <label class="fl" for="c-slug">Адрес
            <input id="c-slug" type="text" name="c-slug" [(ngModel)]="slug" placeholder="osenniy-pirog-goda">
            <span class="muted weak">{{ siteUrl }}/contests/{{ slug || '…' }}</span>
          </label>

          <label class="fl" for="c-lead">Короткий лид
            <textarea id="c-lead" name="c-lead" rows="2" [(ngModel)]="lead"
                      placeholder="Пара предложений для карточки конкурса"></textarea>
          </label>

          <label class="fl" for="c-desc">Описание
            <textarea id="c-desc" name="c-desc" rows="5" [(ngModel)]="description"
                      placeholder="О чём конкурс и зачем в нём участвовать"></textarea>
          </label>

          <label class="fl" for="c-rules">Условия участия
            <textarea id="c-rules" name="c-rules" rows="5" [(ngModel)]="rules"
                      placeholder="Что именно присылать и как будут выбирать победителей"></textarea>
          </label>

          <label class="fl" for="c-prize">Приз
            <input id="c-prize" type="text" name="c-prize" [(ngModel)]="prize"
                   placeholder="Например, набор форм для выпечки">
          </label>
        </section>

        <section class="panel">
          <h2>Сроки</h2>
          <p class="muted weak">
            Порядок дат проверяет бэкенд: приём работ идёт раньше голосования, итоги объявляются последними.
            Опубликовать конкурс без даты начала приёма работ нельзя.
          </p>

          <div class="two">
            <label class="fl" for="c-so">Приём работ открывается
              <input id="c-so" type="datetime-local" name="c-so" [(ngModel)]="submissionsOpenAt">
            </label>
            <label class="fl" for="c-sc">Приём работ закрывается
              <input id="c-sc" type="datetime-local" name="c-sc" [(ngModel)]="submissionsCloseAt">
            </label>
          </div>

          <div class="two">
            <label class="fl" for="c-vo">Голосование открывается
              <input id="c-vo" type="datetime-local" name="c-vo" [(ngModel)]="votingOpenAt">
            </label>
            <label class="fl" for="c-vc">Голосование закрывается
              <input id="c-vc" type="datetime-local" name="c-vc" [(ngModel)]="votingCloseAt">
            </label>
          </div>

          <label class="fl half" for="c-res">Объявление итогов
            <input id="c-res" type="datetime-local" name="c-res" [(ngModel)]="resultsAt">
          </label>
        </section>
      </div>

      <aside class="side">
        <section class="panel">
          <h2>Работы</h2>

          <label class="fl" for="c-kind">Тип работ
            <select id="c-kind" name="c-kind" [(ngModel)]="kind">
              @for (k of kindOptions; track k.value) {
                <option [value]="k.value">{{ k.label }}</option>
              }
            </select>
          </label>

          <label class="fl" for="c-max">Работ от одного участника
            <input id="c-max" type="number" name="c-max" min="1" [(ngModel)]="maxEntriesPerUser">
          </label>
        </section>

        <section class="panel">
          <h2>Обложка</h2>
          @if (cover(); as c) {
            <div class="cover">
              <img [src]="c.url" [alt]="c.alt || 'Обложка конкурса'">
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
    .half { max-width: 260px; }
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
export class ContestEditPage implements OnInit {
  private api = inject(ApiService);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private toasts = inject(ToastService);
  siteUrl = inject(SITE_URL);

  readonly kindOptions = (Object.keys(CONTEST_KIND_LABELS) as ContestKind[]).map((value) => ({
    value,
    label: CONTEST_KIND_LABELS[value],
  }));

  readonly id = signal<number | null>(null);
  readonly status = signal<PublishStatus | null>(null);
  readonly phaseLabel = signal('');
  readonly note = signal<string | null>(null);
  readonly entriesCount = signal(0);
  readonly votesCount = signal(0);
  readonly saving = signal(false);
  readonly moving = signal(false);
  readonly uploading = signal(false);
  readonly cover = signal<Media | null>(null);
  readonly pending = signal<MoveButton | null>(null);

  title = '';
  slug = '';
  lead = '';
  description = '';
  rules = '';
  prize = '';
  kind: ContestKind = 'photo';
  maxEntriesPerUser = 1;
  submissionsOpenAt = '';
  submissionsCloseAt = '';
  votingOpenAt = '';
  votingCloseAt = '';
  resultsAt = '';

  ngOnInit(): void {
    const param = this.route.snapshot.paramMap.get('id');
    if (param && param !== 'new') {
      this.id.set(Number(param));
      this.load(Number(param));
    }
  }

  private load(id: number): void {
    this.api.adminContest(id).subscribe({
      next: (res) => this.fill(res.data),
      error: (err) => this.toasts.error(errorText(err, 'Конкурс не найден или нет доступа.')),
    });
  }

  private fill(c: Contest): void {
    this.title = c.title;
    this.slug = c.slug;
    this.lead = c.lead ?? '';
    this.description = c.description ?? '';
    this.rules = c.rules ?? '';
    this.prize = c.prize ?? '';
    this.kind = c.kind;
    this.maxEntriesPerUser = c.max_entries_per_user;

    this.submissionsOpenAt = toLocalInput(c.submissions_open_at);
    this.submissionsCloseAt = toLocalInput(c.submissions_close_at);
    this.votingOpenAt = toLocalInput(c.voting_open_at);
    this.votingCloseAt = toLocalInput(c.voting_close_at);
    this.resultsAt = toLocalInput(c.results_at);

    this.status.set(c.status);
    this.phaseLabel.set(c.phase_label);
    this.note.set(c.moderation_note ?? null);
    this.entriesCount.set(c.entries_count);
    this.votesCount.set(c.votes_count);
    this.cover.set(c.cover ?? null);
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
    // Непосохранённому конкурсу статус менять нечему.
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
      rules: this.rules.trim() || null,
      prize: this.prize.trim() || null,
      kind: this.kind,
      cover_media_id: this.cover()?.id ?? null,
      max_entries_per_user: this.maxEntriesPerUser,
      submissions_open_at: fromLocalInput(this.submissionsOpenAt),
      submissions_close_at: fromLocalInput(this.submissionsCloseAt),
      voting_open_at: fromLocalInput(this.votingOpenAt),
      voting_close_at: fromLocalInput(this.votingCloseAt),
      results_at: fromLocalInput(this.resultsAt),
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
      ? this.api.updateContest(id, this.payload())
      : this.api.createContest(this.payload());

    request.subscribe({
      next: (res) => {
        this.saving.set(false);
        this.toasts.show('Сохранено');
        this.fill(res.data);

        if (!id) {
          this.id.set(res.data.id);
          this.router.navigate(['/contests', res.data.id], { replaceUrl: true });
        }
      },
      error: (err) => {
        this.saving.set(false);
        // Порядок дат и полноту проверяет бэкенд — его текст понятнее нашего.
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
    this.api.moveContest(id, action).subscribe({
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
