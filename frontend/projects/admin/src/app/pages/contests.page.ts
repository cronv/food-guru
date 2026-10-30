import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';

import { ApiService } from '@shared/services/api.service';
import { ContestCard, PUBLISH_STATUS_LABELS, PublishStatus } from '@shared/models/api.models';
import { errorText, formatDate, plural } from '@shared/utils';
import { ToastService } from '../services/toast.service';

@Component({
    selector: 'adm-contests',
    imports: [RouterLink, FormsModule],
    template: `
    <header class="head">
      <div>
        <h1>Конкурсы</h1>
        <p class="muted">{{ total() }} {{ plural(total(), 'конкурс', 'конкурса', 'конкурсов') }}</p>
      </div>
      <a class="btn btn-green" routerLink="/contests/new">Новый конкурс</a>
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
             placeholder="Поиск по названию" aria-label="Поиск по конкурсам">
      <button class="btn btn-ghost btn-sm" type="button" (click)="load()">Найти</button>
    </div>

    @if (loading() && !rows().length) {
      <div class="loading"><div class="spinner"></div></div>
    } @else if (rows().length) {
      <div class="scroll">
        <table>
          <thead>
            <tr>
              <th>Конкурс</th>
              <th>Тип работ</th>
              <th>Статус</th>
              <th>Фаза</th>
              <th class="num">Работы</th>
              <th class="num">Голоса</th>
              <th>Ближайший срок</th>
              <th class="right">Действия</th>
            </tr>
          </thead>
          <tbody>
            @for (c of rows(); track c.id) {
              <tr>
                <td><a class="name" [routerLink]="['/contests', c.id]">{{ c.title }}</a></td>
                <td class="muted">{{ c.kind_label }}</td>
                <td><span class="chip" [class]="chipClass(c.status)">{{ statusLabel(c.status) }}</span></td>
                <td class="muted">{{ c.phase_label }}</td>
                <td class="num tnum">{{ c.entries_count }}</td>
                <td class="num tnum">{{ c.votes_count }}</td>
                <td class="muted">{{ formatDate(c.deadline_at) || '—' }}</td>
                <td class="right">
                  <div class="row end">
                    @if (c.entries_count) {
                      <a class="btn btn-primary btn-sm" [routerLink]="['/contests', c.id, 'entries']">
                        Разобрать работы ({{ c.entries_count }})
                      </a>
                    }
                    <a class="btn btn-ghost btn-sm" [routerLink]="['/contests', c.id]">Открыть</a>
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
        <p class="muted">Поменяйте фильтр или создайте конкурс.</p>
        <a class="btn btn-green" routerLink="/contests/new">Новый конкурс</a>
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
    .filters { display: flex; gap: 10px; margin-bottom: 16px; max-width: 480px; }
    .name { font-weight: 600; }
    .num { text-align: right; }
    .right { text-align: right; }
    .end { justify-content: flex-end; }
    .more { display: flex; justify-content: center; margin-top: 18px; }
    .loading { display: grid; place-items: center; min-height: 30vh; }
  `]
})
export class ContestsPage implements OnInit {
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

  status = '';
  q = '';

  readonly rows = signal<ContestCard[]>([]);
  readonly total = signal(0);
  readonly page = signal(1);
  readonly lastPage = signal(1);
  readonly loading = signal(true);

  /** Шаблонам нужны функции из utils — пробрасываем их как методы. */
  readonly formatDate = formatDate;
  readonly plural = plural;

  ngOnInit(): void {
    // С дашборда приходят со статусом в адресе, чтобы сразу видеть нужную выборку.
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

    this.api.adminContests({ status: this.status || undefined, q: this.q || undefined, page }).subscribe({
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
