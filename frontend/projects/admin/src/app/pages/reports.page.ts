import { Component, OnInit, inject, signal } from '@angular/core';

import { ApiService } from '@shared/services/api.service';
import { RecipeReportRow, ReportStatus } from '@shared/models/api.models';
import { SITE_URL } from '@shared/tokens';
import { errorText, formatShortDate } from '@shared/utils';
import { ToastService } from '../services/toast.service';

const FILTERS: { value: ReportStatus | 'all'; label: string }[] = [
  { value: 'open', label: 'Новые' },
  { value: 'resolved', label: 'Исправлено' },
  { value: 'rejected', label: 'Отклонено' },
  { value: 'all', label: 'Все' },
];

@Component({
  selector: 'adm-reports',
  standalone: true,
  template: `
    <header class="head">
      <h1>Жалобы читателей</h1>
      <p class="muted">Новых: {{ openTotal() }}. Исправьте рецепт и закройте жалобу, либо отклоните её.</p>
    </header>

    <div class="filters" role="group" aria-label="Статус жалоб">
      @for (f of filters; track f.value) {
        <button type="button" class="btn btn-sm" [class.btn-green]="status() === f.value"
                [class.btn-ghost]="status() !== f.value" [attr.aria-pressed]="status() === f.value"
                (click)="setStatus(f.value)">{{ f.label }}</button>
      }
    </div>

    @if (loading() && !rows().length) {
      <div class="loading"><div class="spinner"></div></div>
    } @else if (rows().length) {
      <div class="list">
        @for (r of rows(); track r.id) {
          <article class="item">
            <div class="top">
              <span class="chip" [class.chip-b]="r.status === 'open'" [class.chip-g]="r.status === 'resolved'">
                {{ r.reason_label }}
              </span>
              @if (r.recipe; as rec) {
                <a class="title" [href]="siteUrl + '/recipes/' + rec.slug" target="_blank" rel="noopener">{{ rec.title }} ↗</a>
              }
              <span class="muted when">{{ when(r.created_at) }}</span>
            </div>
            @if (r.message) { <p class="msg">{{ r.message }}</p> }
            <p class="muted who">
              {{ r.author?.name }} · {{ r.author?.email }}
              @if (r.resolver) { · закрыл {{ r.resolver }} }
            </p>
            @if (r.status === 'open') {
              <div class="actions">
                <button class="btn btn-green btn-sm" type="button" [disabled]="busy() === r.id"
                        (click)="resolve(r, 'resolved')">Исправлено</button>
                <button class="btn btn-ghost btn-sm" type="button" [disabled]="busy() === r.id"
                        (click)="resolve(r, 'rejected')">Отклонить</button>
              </div>
            }
          </article>
        }
      </div>

      @if (page() < lastPage()) {
        <div class="more">
          <button class="btn btn-ghost" type="button" (click)="load(page() + 1)">Показать ещё</button>
        </div>
      }
    } @else {
      <div class="empty"><h3>Жалоб нет</h3><p class="muted">В этом разделе пока пусто.</p></div>
    }
  `,
  styles: [`
    .head { margin-bottom: 16px; }
    h1 { font-size: 30px; }
    .head p { margin: 4px 0 0; font-size: 14px; }
    .filters { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 18px; }
    .list { display: flex; flex-direction: column; gap: 12px; }
    .item { background: var(--surface); border: 1px solid var(--line); border-radius: 16px; padding: 16px 20px; }
    .top { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
    .title { font-weight: 700; }
    .when { margin-left: auto; font-size: 12px; }
    .msg { margin: 10px 0 0; line-height: 1.5; }
    .who { margin: 8px 0 0; font-size: 13px; }
    .actions { display: flex; gap: 8px; margin-top: 12px; }
    .more { display: flex; justify-content: center; margin-top: 18px; }
    .loading { display: grid; place-items: center; min-height: 30vh; }
  `],
})
export class ReportsPage implements OnInit {
  private loadSeq = 0;

  private api = inject(ApiService);
  private toasts = inject(ToastService);
  readonly siteUrl = inject(SITE_URL);

  readonly filters = FILTERS;
  readonly status = signal<ReportStatus | 'all'>('open');
  readonly rows = signal<RecipeReportRow[]>([]);
  readonly openTotal = signal(0);
  readonly page = signal(1);
  readonly lastPage = signal(1);
  readonly loading = signal(true);
  readonly busy = signal<number | null>(null);

  ngOnInit(): void {
    this.load();
  }

  setStatus(status: ReportStatus | 'all'): void {
    this.status.set(status);
    this.rows.set([]);
    this.load();
  }

  load(page = 1): void {
    this.loading.set(true);
    const seq = ++this.loadSeq;

    this.api.adminReports(this.status(), page).subscribe({
      next: (res) => {
        if (seq !== this.loadSeq) return;
        this.rows.set(page === 1 ? res.data : [...this.rows(), ...res.data]);
        this.openTotal.set(res.meta.open_total);
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

  resolve(row: RecipeReportRow, status: 'resolved' | 'rejected'): void {
    this.busy.set(row.id);
    this.api.resolveReport(row.id, status).subscribe({
      next: () => {
        this.busy.set(null);
        this.toasts.show(status === 'resolved' ? 'Жалоба закрыта' : 'Жалоба отклонена');
        this.load();
      },
      error: (err) => {
        this.busy.set(null);
        this.toasts.error(errorText(err));
      },
    });
  }

  when(iso: string | null): string {
    return formatShortDate(iso);
  }
}
