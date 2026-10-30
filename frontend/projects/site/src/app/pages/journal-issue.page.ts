import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';

import { ApiService } from '@shared/services/api.service';
import { JournalIssue, JournalIssueCard } from '@shared/models/api.models';
import { formatDate, pluralize } from '@shared/utils';
import { HttpStatusService } from '../services/http-status.service';
import { SeoService } from '../services/seo.service';

@Component({
    selector: 'app-journal-issue',
    imports: [RouterLink],
    template: `
    @if (issue(); as i) {
      <article class="wrap band">
        <nav class="crumbs muted">
          <a routerLink="/">Главная</a> / <a routerLink="/journal">Журнал</a> / <span>{{ i.title }}</span>
        </nav>

        <header class="top">
          <div class="shot">
            @if (i.cover) {
              <img [src]="i.cover.url" [alt]="i.cover.alt || i.title">
            } @else {
              <!-- Своей обложки у выпуска может не быть, а чужие снимки брать нельзя:
                   рисуем подпись из цветов проекта. -->
              <div class="ph" [class.ph-month]="i.period === 'month'">
                <span class="ph-kind">{{ i.period_label }}</span>
                <span class="ph-range">{{ i.period_range }}</span>
              </div>
            }
          </div>

          <div class="intro">
            <div class="row">
              <span class="chip chip-g">{{ i.period_label }}</span>
              <span class="chip">{{ i.period_range }}</span>
              @if (pagesLabel(i.pages); as pages) { <span class="chip tnum">{{ pages }}</span> }
            </div>

            <h1>{{ i.title }}</h1>
            @if (i.lead) { <p class="lead muted">{{ i.lead }}</p> }

            <div class="actions">
              <!-- Обычная ссылка: PDF отдаёт сервер, он же считает скачивания. -->
              @if (i.has_file) {
                <a class="btn btn-green get" [href]="api.journalDownloadUrl(i.slug)" download
                   [attr.aria-label]="downloadLabel(i)">Скачать PDF</a>
                <p class="muted small tnum">{{ fileLine(i) }}</p>
              } @else {
                <p class="muted small">
                  Файл выпуска готовится. Кнопка скачивания появится здесь, как только редакция
                  загрузит PDF.
                </p>
              }
            </div>

            <dl class="facts">
              <div><dt>Срок выпуска</dt><dd>{{ i.period_range }}</dd></div>
              @if (i.issue_date) {
                <div><dt>Дата выпуска</dt><dd>{{ date(i.issue_date) }}</dd></div>
              }
              @if (pagesLabel(i.pages); as pages) {
                <div><dt>Объём</dt><dd class="tnum">{{ pages }}</dd></div>
              }
              @if (sizeMb(i.file_size); as size) {
                <div><dt>Размер файла</dt><dd class="tnum">{{ size }}</dd></div>
              }
              @if (i.downloads_count) {
                <div><dt>Скачиваний</dt><dd class="tnum">{{ i.downloads_count }}</dd></div>
              }
            </dl>
          </div>
        </header>

        @if (i.description) {
          <section class="block">
            <div class="sec-head"><h2>О выпуске</h2></div>
            <p class="flow">{{ i.description }}</p>
          </section>
        }

        <section class="block">
          <div class="sec-head">
            <h2>Другие выпуски <span class="tnum">{{ i.issue_year }}</span> года</h2>
            <a class="btn btn-ghost btn-sm" routerLink="/journal">Все выпуски →</a>
          </div>

          @if (siblings().length) {
            <div class="grid near">
              @for (s of siblings(); track s.slug) {
                <a class="card one" [routerLink]="['/journal', s.slug]">
                  <span class="chip chip-g">{{ s.period_label }}</span>
                  <span class="one-range">{{ s.period_range }}</span>
                  <span class="one-title">{{ s.title }}</span>
                </a>
              }
            </div>
          } @else {
            <p class="muted">Других выпусков этого года в журнале пока нет.</p>
          }
        </section>
      </article>
    } @else if (error()) {
      <div class="wrap band empty">
        <h1>Выпуск не найден</h1>
        <p class="muted">{{ error() }}</p>
        <a class="btn btn-green" routerLink="/journal">Все выпуски</a>
      </div>
    } @else {
      <div class="wrap band loading"><div class="spinner"></div></div>
    }
  `,
    styles: [`
    .crumbs { font-size: 13px; margin-bottom: 14px; }
    .top { display: flex; flex-wrap: wrap; gap: 30px; align-items: flex-start; }
    .shot {
      flex: 1 1 340px; min-width: 0; height: 420px;
      border-radius: 20px; overflow: hidden; background: var(--sunken);
    }
    .shot img { width: 100%; height: 100%; object-fit: cover; }
    .ph {
      width: 100%; height: 100%; padding: 24px; box-sizing: border-box;
      display: flex; flex-direction: column; justify-content: flex-end; gap: 8px;
      background: var(--green-soft); color: var(--green-ink);
    }
    .ph-month { background: var(--brick-soft); color: var(--brick-ink); }
    .ph-kind { font-size: 12px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; }
    .ph-range { font-family: var(--display); font-size: 32px; font-weight: 600; line-height: 1.12; }
    .intro { flex: 999 1 420px; min-width: 0; display: flex; flex-direction: column; gap: 14px; }
    h1 { font-size: clamp(28px, 4.2vw, 42px); line-height: 1.1; }
    .lead { margin: 0; font-size: 17px; max-width: 62ch; }
    .actions { display: flex; flex-wrap: wrap; gap: 10px 16px; align-items: center; }
    .get { min-height: 52px; padding: 0 26px; font-size: 16px; }
    .small { margin: 0; font-size: 13px; }
    .facts { margin: 0; display: flex; flex-direction: column; gap: 8px; }
    .facts > div {
      display: flex; justify-content: space-between; gap: 14px; align-items: baseline;
      padding-bottom: 8px; border-bottom: 1px solid var(--line);
    }
    .facts dt { font-size: 13px; color: var(--muted); }
    .facts dd { margin: 0; font-size: 15px; font-weight: 700; text-align: right; }
    .block { margin-top: 44px; }
    .flow { margin: 0; white-space: pre-line; line-height: 1.7; max-width: 76ch; }
    .near { grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
    .one { gap: 8px; padding: 16px; align-items: flex-start; }
    .one-range { font-size: 13px; color: var(--muted); font-weight: 600; }
    .one-title { font-family: var(--display); font-size: 19px; font-weight: 600; line-height: 1.25; }
    .loading { display: grid; place-items: center; min-height: 50vh; }
  `]
})
export class JournalIssuePage implements OnInit {
  /** Публичный: шаблон сам собирает адрес скачивания PDF. */
  readonly api = inject(ApiService);
  private route = inject(ActivatedRoute);
  private seo = inject(SeoService);
  private status = inject(HttpStatusService);

  readonly issue = signal<JournalIssue | null>(null);
  readonly error = signal<string | null>(null);
  /** Остальные выпуски того же года — одним запросом journal({ year }). */
  readonly siblings = signal<JournalIssueCard[]>([]);

  ngOnInit(): void {
    this.route.paramMap.subscribe((params) => {
      const slug = params.get('slug');
      if (!slug) return;
      this.load(slug);
    });
  }

  private load(slug: string): void {
    this.issue.set(null);
    this.error.set(null);
    this.siblings.set([]);

    this.api.journalIssue(slug).subscribe({
      next: (res) => {
        const issue = res.data;
        this.issue.set(issue);

        this.seo.apply({
          title: issue.title,
          description: issue.lead || issue.description,
          image: issue.cover?.url,
          path: `/journal/${issue.slug}`,
          type: 'article',
        });

        this.loadSiblings(issue);
      },
      error: (err) => {
        this.error.set('Возможно, выпуск ещё не вышел или снят с публикации.');
        // Код 404 для робота: «не найдено» не должно попадать в индекс.
        this.status.fromError(err);
      },
    });
  }

  /** Год выпуска известен только из ответа, поэтому соседей грузим после него. */
  private loadSiblings(issue: JournalIssue): void {
    this.api.journal({ year: issue.issue_year }).subscribe({
      next: (res) => this.siblings.set(res.data.filter((s) => s.slug !== issue.slug)),
      error: () => this.siblings.set([]),
    });
  }

  pagesLabel(pages: number | null): string {
    if (!pages) return '';
    return pluralize(pages, 'страница', 'страницы', 'страниц');
  }

  /** 2,4 МБ — размер приходит в байтах. */
  sizeMb(bytes: number | null): string {
    if (!bytes) return '';
    return `${(bytes / (1024 * 1024)).toFixed(1).replace('.', ',')} МБ`;
  }

  /** Строка рядом с кнопкой: PDF · 2,4 МБ · 24 страницы. */
  fileLine(issue: JournalIssue): string {
    const parts = ['PDF'];
    const size = this.sizeMb(issue.file_size);
    if (size) parts.push(size);
    const pages = this.pagesLabel(issue.pages);
    if (pages) parts.push(pages);
    return parts.join(' · ');
  }

  downloadLabel(issue: JournalIssue): string {
    return `Скачать PDF: ${issue.period_label}, ${issue.period_range}`;
  }

  date(iso: string | null): string {
    return formatDate(iso);
  }
}
