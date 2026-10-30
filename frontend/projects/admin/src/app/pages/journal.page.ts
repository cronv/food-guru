import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';

import { ApiService } from '@shared/services/api.service';
import {
  JOURNAL_PERIOD_LABELS, JournalIssueCard, JournalPeriod,
  PUBLISH_STATUS_LABELS, PublishStatus,
} from '@shared/models/api.models';
import { errorText, formatDate, plural, pluralize } from '@shared/utils';
import { ToastService } from '../services/toast.service';

/** 2,4 МБ — размер приходит в байтах. Мелкие файлы считаем в килобайтах,
 *  иначе служебный PDF превращается в бессмысленные «0,0 МБ». */
function sizeLabel(bytes: number | null): string {
  if (!bytes) return '';
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} КБ`;
  return `${(bytes / (1024 * 1024)).toFixed(1).replace('.', ',')} МБ`;
}

@Component({
    selector: 'adm-journal',
    imports: [RouterLink, FormsModule],
    template: `
    <header class="head">
      <div>
        <h1>Журнал</h1>
        <p class="muted">{{ total() }} {{ plural(total(), 'выпуск', 'выпуска', 'выпусков') }}</p>
      </div>
      <a class="btn btn-green" routerLink="/journal/new">Новый выпуск</a>
    </header>

    <div class="tabs" role="group" aria-label="Фильтр по статусу">
      @for (t of statusTabs; track t.value) {
        <button class="tab" type="button" [class.on]="status === t.value"
                [attr.aria-pressed]="status === t.value" (click)="setStatus(t.value)">
          {{ t.label }}
        </button>
      }
    </div>

    <div class="filters">
      <div class="row kinds" role="group" aria-label="Фильтр по типу выпуска">
        @for (p of periodTabs; track p.value) {
          <button class="toggle" type="button" [class.on]="period === p.value"
                  [attr.aria-pressed]="period === p.value" (click)="setPeriod(p.value)">
            {{ p.label }}
          </button>
        }
      </div>

      <input type="search" [(ngModel)]="q" name="q" (keyup.enter)="load()"
             placeholder="Поиск по названию" aria-label="Поиск по выпускам">

      <select [(ngModel)]="year" name="year" (change)="load()" aria-label="Фильтр по году выпуска">
        <option value="">Все годы</option>
        @for (y of yearOptions; track y) {
          <option [value]="y">{{ y }}</option>
        }
      </select>

      <button class="btn btn-ghost btn-sm" type="button" (click)="load()">Найти</button>
    </div>

    @if (loading() && !rows().length) {
      <div class="loading"><div class="spinner"></div></div>
    } @else if (rows().length) {
      @if (withoutFile(); as n) {
        <div class="note note-warn">
          {{ n }} {{ plural(n, 'выпуск', 'выпуска', 'выпусков') }} без PDF — такие
          выпуски отмечены в списке, опубликовать их нельзя. Загрузите файл в редакторе выпуска.
        </div>
      }

      <div class="scroll">
        <table>
          <thead>
            <tr>
              <th>Обложка</th>
              <th>Выпуск</th>
              <th>Тип</th>
              <th>Срок</th>
              <th>Статус</th>
              <th class="num">Объём</th>
              <th class="num">Файл</th>
              <th class="num">Скачиваний</th>
              <th>Опубликован</th>
              <th class="right">Действия</th>
            </tr>
          </thead>
          <tbody>
            @for (i of rows(); track i.id) {
              <tr [class.nofile]="!i.has_file">
                <td>
                  @if (i.cover; as c) {
                    <img class="thumb" [src]="c.url" [alt]="c.alt || 'Обложка выпуска'" loading="lazy">
                  } @else {
                    <!-- Обложки может не быть: вместо чужой картинки показываем подпись. -->
                    <div class="thumb ph muted">PDF</div>
                  }
                </td>
                <td>
                  <a class="name" [routerLink]="['/journal', i.id]">{{ i.title }}</a>
                  @if (!i.has_file) { <span class="chip chip-b flag">Без файла</span> }
                </td>
                <td class="muted">{{ i.period_label }}</td>
                <td class="muted">{{ i.period_range }}</td>
                <td><span class="chip" [class]="chipClass(i.status)">{{ statusLabel(i.status) }}</span></td>
                <td class="num tnum">{{ pagesLabel(i.pages) || '—' }}</td>
                <td class="num tnum">{{ sizeLabel(i.file_size) || '—' }}</td>
                <td class="num tnum">{{ i.downloads_count }}</td>
                <td class="muted">{{ formatDate(i.published_at) || '—' }}</td>
                <td class="right">
                  <div class="row end">
                    <a class="btn btn-ghost btn-sm" [routerLink]="['/journal', i.id]">Открыть</a>
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
        <p class="muted">Поменяйте фильтр или создайте выпуск.</p>
        <a class="btn btn-green" routerLink="/journal/new">Новый выпуск</a>
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
    .filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 16px; }
    .filters input { flex: 1 1 240px; min-width: 0; }
    .filters select { flex: 0 1 170px; }
    .kinds { flex: 0 0 auto; gap: 6px; }
    .toggle {
      min-height: 44px; padding: 0 16px; border: 1px solid var(--line); border-radius: 999px;
      background: var(--surface); color: var(--muted); font-size: 14px;
    }
    .toggle.on { border-color: var(--green); background: var(--green-soft); color: var(--green-ink); font-weight: 700; }
    .note { margin-bottom: 16px; }
    .thumb { width: 44px; height: 58px; object-fit: cover; border-radius: 8px; background: var(--sunken); display: block; }
    .ph {
      display: grid; place-items: center; border: 1px solid var(--line);
      font-size: 11px; font-weight: 700; letter-spacing: .06em;
    }
    .name { font-weight: 600; }
    .flag { margin-left: 8px; }
    /* Выпуск без PDF заметен и в плотной таблице: полоса слева плюс подпись у названия. */
    .nofile td:first-child { box-shadow: inset 3px 0 0 var(--brick); }
    .num { text-align: right; }
    .right { text-align: right; }
    .end { justify-content: flex-end; }
    .more { display: flex; justify-content: center; margin-top: 18px; }
    .loading { display: grid; place-items: center; min-height: 30vh; }
    /* Цели нажатия держим в 44px: мелкие кнопки неудобны на телефоне. */
    .btn-sm { min-height: 44px; }
  `]
})
export class JournalPage implements OnInit {
  /** Номер запроса: ответ, отставший от нового отбора, игнорируем,
   *  иначе он перезапишет свежий список устаревшим. */
  private loadSeq = 0;

  private api = inject(ApiService);
  private route = inject(ActivatedRoute);
  private toasts = inject(ToastService);

  readonly statusTabs = [
    { value: '', label: 'Все' },
    { value: 'draft', label: 'Черновики' },
    { value: 'moderation', label: 'На проверке' },
    { value: 'published', label: 'Опубликованы' },
    { value: 'archived', label: 'Архив' },
  ];

  readonly periodTabs = [
    { value: '', label: 'Любой тип' },
    ...(Object.keys(JOURNAL_PERIOD_LABELS) as JournalPeriod[]).map((value) => ({
      value: value as string,
      label: JOURNAL_PERIOD_LABELS[value],
    })),
  ];

  /**
   * Готового справочника лет для админки в контракте нет, а публичный
   * journal/periods знает только опубликованные выпуски — черновики будущего
   * года из него выпали бы. Поэтому берём год вперёд (январский выпуск
   * готовят в декабре) и пять лет назад.
   */
  readonly yearOptions = (() => {
    const now = new Date().getFullYear();
    const years: string[] = [];
    // Строки, а не числа: <select> всё равно вернёт в ngModel строку.
    for (let y = now + 1; y >= now - 5; y--) years.push(String(y));
    return years;
  })();

  status = '';
  period = '';
  year = '';
  q = '';

  readonly rows = signal<JournalIssueCard[]>([]);
  readonly total = signal(0);
  readonly page = signal(1);
  readonly lastPage = signal(1);
  readonly loading = signal(true);

  /** Сколько загруженных выпусков ещё без PDF — о них предупреждаем над таблицей. */
  readonly withoutFile = computed(() => this.rows().filter((i) => !i.has_file).length);

  readonly formatDate = formatDate;
  readonly plural = plural;
  readonly sizeLabel = sizeLabel;

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

  pagesLabel(pages: number | null): string {
    if (!pages) return '';
    return pluralize(pages, 'страница', 'страницы', 'страниц');
  }

  setStatus(value: string): void {
    this.status = value;
    this.load();
  }

  setPeriod(value: string): void {
    this.period = value;
    this.load();
  }

  load(page = 1): void {
    this.loading.set(true);
    const seq = ++this.loadSeq;

    this.api.adminJournal({
      status: this.status || undefined,
      period: this.period || undefined,
      // Из <select> значение приходит строкой, бэкенд ждёт число.
      year: this.year ? Number(this.year) : undefined,
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
