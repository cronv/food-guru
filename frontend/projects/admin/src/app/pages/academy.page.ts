import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';

import { ApiService } from '@shared/services/api.service';
import {
  ACADEMY_FORMAT_LABELS, AcademyCard, AcademyFormat,
  PUBLISH_STATUS_LABELS, PublishStatus,
} from '@shared/models/api.models';
import { errorText, plural } from '@shared/utils';
import { ToastService } from '../services/toast.service';

/** 27 октября 2026, 19:00 — для занятия важно не только число, но и время. */
function formatWhen(iso: string | null): string {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  return d.toLocaleString('ru-RU', {
    day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit',
  });
}

@Component({
    selector: 'adm-academy',
    imports: [RouterLink, FormsModule],
    template: `
    <header class="head">
      <div>
        <h1>Академия</h1>
        <p class="muted">{{ total() }} {{ plural(total(), 'занятие', 'занятия', 'занятий') }}</p>
      </div>
      <a class="btn btn-green" routerLink="/academy/new">Новое занятие</a>
    </header>

    <div class="tabs" role="group" aria-label="Фильтр по статусу">
      @for (t of tabs; track t.value) {
        <button class="tab" type="button" [class.on]="status === t.value"
                [attr.aria-pressed]="status === t.value" (click)="setStatus(t.value)">
          {{ t.label }}
        </button>
      }
    </div>

    <div class="filters">
      <input type="search" [(ngModel)]="q" name="q" (keyup.enter)="load()"
             placeholder="Поиск по названию" aria-label="Поиск по занятиям">
      <select [(ngModel)]="format" name="format" (change)="load()" aria-label="Фильтр по формату">
        <option value="">Все форматы</option>
        @for (f of formatOptions; track f.value) {
          <option [value]="f.value">{{ f.label }}</option>
        }
      </select>
      <button class="btn btn-ghost btn-sm" type="button" (click)="load()">Найти</button>
    </div>

    @if (loading() && !rows().length) {
      <div class="loading"><div class="spinner"></div></div>
    } @else if (rows().length) {
      <div class="scroll">
        <table>
          <thead>
            <tr>
              <th>Занятие</th>
              <th>Формат</th>
              <th>Уровень</th>
              <th>Статус</th>
              <th>Старт</th>
              <th class="num">Длительность</th>
              <th class="num">Записались</th>
              <th class="right">Действия</th>
            </tr>
          </thead>
          <tbody>
            @for (s of rows(); track s.id) {
              <tr>
                <td>
                  <a class="name" [routerLink]="['/academy', s.id]">{{ s.title }}</a>
                  @if (s.is_live_now) { <span class="chip chip-b live">Идёт сейчас</span> }
                </td>
                <td class="muted">{{ s.format_label }}</td>
                <td class="muted">{{ s.level_label }}</td>
                <td><span class="chip" [class]="chipClass(s.status)">{{ statusLabel(s.status) }}</span></td>
                <td class="muted">{{ formatWhen(s.starts_at) || '—' }}</td>
                <td class="num tnum">
                  {{ s.duration_minutes ? s.duration_minutes + ' мин' : '—' }}
                </td>
                <td class="num tnum">{{ s.registrations_count }}</td>
                <td class="right">
                  <div class="row end">
                    @if (s.registrations_count) {
                      <a class="btn btn-ghost btn-sm" [routerLink]="['/academy', s.id, 'registrations']">
                        Записавшиеся
                      </a>
                    }
                    <a class="btn btn-ghost btn-sm" [routerLink]="['/academy', s.id]">Открыть</a>
                  </div>
                </td>
              </tr>
            }
          </tbody>
        </table>
      </div>

      @if (page() < lastPage()) {
        <div class="more">
          <button class="btn btn-ghost" type="button" [disabled]="loading()" (click)="load(page() + 1)">
            Показать ещё
          </button>
        </div>
      }
    } @else {
      <div class="empty">
        <h3>Здесь пока пусто</h3>
        <p class="muted">Поменяйте фильтр или создайте занятие.</p>
        <a class="btn btn-green" routerLink="/academy/new">Новое занятие</a>
      </div>
    }
  `,
    styles: [`
    .head { display: flex; flex-wrap: wrap; gap: 16px; align-items: center; justify-content: space-between; margin-bottom: 18px; }
    h1 { font-size: 30px; }
    .head p { margin: 4px 0 0; font-size: 14px; }
    .tabs { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--line); }
    .tab { min-height: 44px; padding: 0 16px; border: 0; border-radius: 999px; background: none; color: var(--muted); font-size: 14px; }
    .tab.on { background: var(--panel); color: #fff; font-weight: 700; }
    .filters { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; }
    .filters input { flex: 1 1 240px; min-width: 0; }
    .filters select { flex: 0 1 200px; }
    .name { font-weight: 600; }
    .live { margin-left: 8px; }
    .num { text-align: right; }
    .right { text-align: right; }
    .end { justify-content: flex-end; }
    .more { display: flex; justify-content: center; margin-top: 18px; }
    .loading { display: grid; place-items: center; min-height: 30vh; }
  `]
})
export class AcademyPage implements OnInit {
  /** Номер запроса: ответ, отставший от нового отбора, игнорируем,
   *  иначе он перезапишет свежий список устаревшим. */
  private loadSeq = 0;

  private api = inject(ApiService);
  private route = inject(ActivatedRoute);
  private toasts = inject(ToastService);

  readonly tabs = [
    { value: '', label: 'Все' },
    { value: 'draft', label: 'Черновики' },
    { value: 'moderation', label: 'На проверке' },
    { value: 'published', label: 'Опубликованы' },
    { value: 'archived', label: 'Архив' },
  ];

  readonly formatOptions = (Object.keys(ACADEMY_FORMAT_LABELS) as AcademyFormat[]).map((value) => ({
    value,
    label: ACADEMY_FORMAT_LABELS[value],
  }));

  status = '';
  format = '';
  q = '';

  readonly rows = signal<AcademyCard[]>([]);
  readonly total = signal(0);
  readonly page = signal(1);
  readonly lastPage = signal(1);
  readonly loading = signal(true);

  readonly formatWhen = formatWhen;
  readonly plural = plural;

  ngOnInit(): void {
    const fromQuery = this.route.snapshot.queryParamMap.get('status');
    if (fromQuery) this.status = fromQuery;
    this.load();
  }

  statusLabel(status: PublishStatus): string {
    return PUBLISH_STATUS_LABELS[status];
  }

  chipClass(status: PublishStatus): string {
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

    this.api.adminAcademy({
      status: this.status || undefined,
      format: this.format || undefined,
      q: this.q || undefined,
      page,
    }).subscribe({
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
}
