import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';

import { ApiService, JournalFilters } from '@shared/services/api.service';
import {
  JournalIssueCard, JournalMonth, JournalPeriod, JournalYear, JOURNAL_PERIOD_LABELS,
} from '@shared/models/api.models';
import { pluralize } from '@shared/utils';
import { SeoService } from '../services/seo.service';

@Component({
    selector: 'app-journal',
    imports: [RouterLink, FormsModule],
    template: `
    <div class="wrap band">
      <h1>Журнал</h1>
      <p class="muted sub">
        Гайды недели и месяца одним файлом: рецепты, закупки и заготовки, собранные редакцией.
      </p>

      <div class="tabs" role="group" aria-label="Какие выпуски показать">
        @for (t of periodTabs; track t.label) {
          <button class="tab" type="button" [class.on]="period() === t.value"
                  [attr.aria-pressed]="period() === t.value" (click)="setPeriod(t.value)">
            {{ t.label }}
          </button>
        }
      </div>

      <div class="bar">
        <form class="find" (submit)="submitSearch($event)">
          <label class="fl" for="jq">Поиск по названию
            <input id="jq" type="search" name="jq" [(ngModel)]="q" placeholder="Например, заготовки">
          </label>
          <button class="btn btn-ghost btn-sm search-go" type="submit">Найти</button>
        </form>

        <!-- Года и месяцы приходят отдельным запросом, поэтому список не схлопывается
             под уже выбранный отбор: видно, где ещё есть выпуски. -->
        @if (years().length) {
          <div class="picks">
            <div class="group">
              <span class="muted label">Год</span>
              <div class="row" role="group" aria-label="Год выпуска">
                @for (y of years(); track y.year) {
                  <button class="toggle" type="button" [class.on]="year() === y.year"
                          [attr.aria-pressed]="year() === y.year" (click)="toggleYear(y.year)">
                    <span class="tnum">{{ y.year }}</span>
                    <span class="n tnum">{{ y.count }}</span>
                  </button>
                }
              </div>
            </div>

            @if (months().length) {
              <div class="group">
                <span class="muted label">Месяц</span>
                <div class="row" role="group" aria-label="Месяц выпуска">
                  @for (m of months(); track m.month) {
                    <button class="toggle" type="button" [class.on]="month() === m.month"
                            [attr.aria-pressed]="month() === m.month" (click)="toggleMonth(m.month)">
                      {{ m.label }}
                      <span class="n tnum">{{ m.count }}</span>
                    </button>
                  }
                </div>
              </div>
            }
          </div>
        }
      </div>

      @if (hasFilters()) {
        <div class="row applied">
          @if (q) { <span class="chip chip-g">{{ q }}</span> }
          @if (periodLabel(); as label) { <span class="chip chip-g">{{ label }}</span> }
          @if (year() !== null) { <span class="chip chip-g tnum">{{ year() }}</span> }
          @if (monthLabel(); as label) { <span class="chip chip-g">{{ label }}</span> }
          <button class="reset" type="button" (click)="reset()">Сбросить условия</button>
        </div>
      }

      <p class="muted count">{{ total() !== null ? found() : 'Загружаем…' }}</p>

      @if (loading()) {
        <div class="loading"><div class="spinner"></div></div>
      } @else if (items().length) {
        <div class="grid cols">
          @for (i of items(); track i.slug) {
            <article class="card tile">
              <div class="shot">
                @if (i.cover) {
                  <img [src]="i.cover.url" [alt]="i.cover.alt || i.title" loading="lazy">
                } @else {
                  <!-- Своей обложки у выпуска может не быть, а чужие снимки брать нельзя:
                       рисуем подпись из цветов проекта. -->
                  <div class="ph" [class.ph-month]="i.period === 'month'">
                    <span class="ph-kind">{{ i.period_label }}</span>
                    <span class="ph-range">{{ i.period_range }}</span>
                  </div>
                }
              </div>

              <div class="row tags">
                <span class="chip chip-g">{{ i.period_label }}</span>
                <span class="chip">{{ i.period_range }}</span>
              </div>

              <h2 class="name">
                <a [routerLink]="['/journal', i.slug]">{{ i.title }}</a>
              </h2>
              @if (i.lead) { <p class="lead muted">{{ i.lead }}</p> }

              <div class="facts">
                @if (pagesLabel(i.pages); as pages) {
                  <div><span class="k">Объём</span><span class="v tnum">{{ pages }}</span></div>
                }
                @if (sizeMb(i.file_size); as size) {
                  <div><span class="k">Файл</span><span class="v tnum">{{ size }}</span></div>
                }
              </div>

              <!-- Обычная ссылка: PDF отдаёт сервер, он же считает скачивания. -->
              @if (i.has_file) {
                <a class="btn btn-green get" [href]="api.journalDownloadUrl(i.slug)" download
                   [attr.aria-label]="downloadLabel(i)">Скачать PDF</a>
              } @else {
                <p class="muted small">Файл выпуска готовится.</p>
              }
            </article>
          }
        </div>

        @if (page() < lastPage()) {
          <div class="more">
            <button class="btn btn-ghost" type="button" (click)="loadMore()">Показать ещё</button>
          </div>
        }
      } @else if (failed()) {
        <div class="empty">
          <h2>Не удалось загрузить журнал</h2>
          <p class="muted">Похоже, связь с сервером пропала. Обновите страницу через минуту.</p>
          <button class="btn btn-green" type="button" (click)="apply()">Попробовать снова</button>
        </div>
      } @else {
        <div class="empty">
          <h2>{{ emptyTitle() }}</h2>
          <p class="muted">{{ emptyHint() }}</p>
          @if (hasFilters()) {
            <div class="row center">
              <button class="btn btn-green btn-sm" type="button" (click)="reset()">
                Сбросить условия
              </button>
              <a class="btn btn-ghost btn-sm" routerLink="/recipes">К каталогу рецептов</a>
            </div>
          }
        </div>
      }
    </div>
  `,
    styles: [`
    h1 { font-size: clamp(28px, 4vw, 40px); }
    .sub { margin: 6px 0 20px; max-width: 62ch; }
    .tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 18px; }
    .tab {
      min-height: 44px; padding: 0 18px; border-radius: 999px;
      border: 1px solid var(--line); background: var(--surface); color: var(--muted); font-weight: 600;
    }
    .tab.on { background: var(--green); border-color: var(--green); color: var(--on-accent); }
    .bar {
      display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-end;
      justify-content: space-between; padding-bottom: 18px; border-bottom: 1px solid var(--line);
    }
    .find { display: flex; align-items: flex-end; gap: 10px; flex: 1 1 300px; min-width: 0; }
    .find label { flex: 1 1 auto; min-width: 0; }
    .search-go { min-height: 44px; }
    .picks { display: flex; flex-direction: column; gap: 14px; }
    .group { display: flex; flex-direction: column; gap: 8px; }
    .group .label { font-size: 13px; font-weight: 700; }
    .toggle {
      display: inline-flex; align-items: center; gap: 8px;
      min-height: 44px; padding: 0 16px; border-radius: 999px;
      border: 1px solid var(--line); background: var(--surface); color: var(--muted); font-weight: 600;
    }
    .toggle.on { background: var(--green-soft); border-color: var(--green); color: var(--green-ink); }
    .toggle .n { font-size: 12px; font-weight: 700; opacity: .7; }
    .applied { align-items: center; margin-top: 16px; }
    .reset { border: 0; background: none; color: var(--brick); font-weight: 600; min-height: 44px; padding: 0 4px; }
    .count { margin: 18px 0 0; font-size: 14px; }
    .cols { grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); margin-top: 18px; }
    .tile { gap: 12px; padding: 14px; }
    .shot { height: 200px; border-radius: 14px; overflow: hidden; background: var(--sunken); }
    .shot img { width: 100%; height: 100%; object-fit: cover; }
    .ph {
      width: 100%; height: 100%; padding: 16px; box-sizing: border-box;
      display: flex; flex-direction: column; justify-content: flex-end; gap: 6px;
      background: var(--green-soft); color: var(--green-ink);
    }
    .ph-month { background: var(--brick-soft); color: var(--brick-ink); }
    .ph-kind { font-size: 11px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; }
    .ph-range { font-family: var(--display); font-size: 24px; font-weight: 600; line-height: 1.15; }
    .tags { padding: 0 2px; }
    .name { font-size: 22px; line-height: 1.2; padding: 0 2px; }
    .lead { margin: 0; padding: 0 2px; font-size: 14px; line-height: 1.55; }
    .facts {
      display: flex; flex-direction: column; gap: 8px; margin-top: auto;
      padding: 12px 2px 2px; border-top: 1px solid var(--line);
    }
    .facts > div { display: flex; justify-content: space-between; gap: 12px; align-items: baseline; }
    .facts .k { font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); }
    .facts .v { font-size: 14px; font-weight: 700; text-align: right; }
    .get { align-self: flex-start; margin: 2px 2px 0; }
    .small { margin: 2px; font-size: 13px; }
    .loading { display: grid; place-items: center; min-height: 240px; }
    .more { display: flex; justify-content: center; margin-top: 28px; }
    .center { justify-content: center; margin-top: 14px; }
    .empty { margin-top: 20px; }
  `]
})
export class JournalPage implements OnInit {
  /** Номер запроса: ответ, отставший от нового отбора, игнорируем,
   *  иначе он перезапишет свежий список устаревшим. */
  private loadSeq = 0;

  /** Публичный: шаблон сам собирает адрес скачивания PDF. */
  readonly api = inject(ApiService);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private seo = inject(SeoService);

  readonly periodTabs: { value: JournalPeriod | null; label: string }[] = [
    { value: null, label: 'Все выпуски' },
    { value: 'week', label: 'Гайды недели' },
    { value: 'month', label: 'Гайды месяца' },
  ];

  /** Поиск правит ngModel, поэтому это обычное поле, а не сигнал. */
  q = '';

  readonly year = signal<number | null>(null);
  readonly month = signal<number | null>(null);
  readonly period = signal<JournalPeriod | null>(null);

  readonly items = signal<JournalIssueCard[]>([]);
  readonly total = signal<number | null>(null);
  readonly page = signal(1);
  readonly lastPage = signal(1);
  readonly loading = signal(true);
  readonly failed = signal(false);

  readonly years = signal<JournalYear[]>([]);
  /** Сколько выпусков опубликовано всего: отличает пустой журнал от пустого отбора. */
  readonly publishedTotal = signal<number | null>(null);

  /** Месяцы показываем только у раскрытого года — иначе список не читается. */
  readonly months = computed<JournalMonth[]>(() => {
    const selected = this.year();
    if (selected === null) return [];
    return this.years().find((y) => y.year === selected)?.months ?? [];
  });

  ngOnInit(): void {
    this.seo.apply({
      title: 'Журнал',
      description: 'Гайды недели и месяца «Food Guru» в PDF: рецепты, закупки и заготовки одним файлом.',
      path: '/journal',
    });

    // Дерево годов и месяцев не зависит от отбора, поэтому запрашиваем его один раз.
    this.api.journalPeriods().subscribe({
      next: (res) => {
        this.years.set(res.data);
        this.publishedTotal.set(res.meta.total);
      },
      error: () => {
        this.years.set([]);
        this.publishedTotal.set(null);
      },
    });

    // Отбор живёт в адресе: ссылкой на год, месяц или тип выпуска можно поделиться.
    this.route.queryParams.subscribe((params) => {
      this.q = params['q'] ?? '';
      this.year.set(this.asNumber(params['year']));
      this.month.set(this.asNumber(params['month']));
      this.period.set(params['period'] === 'week' || params['period'] === 'month'
        ? params['period']
        : null);

      this.load(1);
    });
  }

  /** Числовые параметры адреса приходят строкой, а мусор в них игнорируем. */
  private asNumber(value: unknown): number | null {
    if (value === undefined || value === null || value === '') return null;
    const n = Number(value);
    return Number.isInteger(n) && n > 0 ? n : null;
  }

  private filters(page: number): JournalFilters {
    return {
      q: this.q || undefined,
      year: this.year() ?? undefined,
      month: this.month() ?? undefined,
      period: this.period() ?? undefined,
      page,
    };
  }

  private load(page: number): void {
    this.loading.set(true);
    this.failed.set(false);

    const seq = ++this.loadSeq;

    this.api.journal(this.filters(page)).subscribe({
      next: (res) => {
        if (seq !== this.loadSeq) return;

        this.items.set(page === 1 ? res.data : [...this.items(), ...res.data]);
        this.total.set(res.meta.total);
        this.page.set(res.meta.current_page);
        this.lastPage.set(res.meta.last_page);
        this.loading.set(false);
      },
      error: () => {
        if (seq !== this.loadSeq) return;

        this.items.set([]);
        this.total.set(0);
        this.failed.set(true);
        this.loading.set(false);
      },
    });
  }

  /** Любое изменение условий переписывает адрес, а перезагрузку делает подписка. */
  private sync(): void {
    this.router.navigate([], {
      relativeTo: this.route,
      queryParams: {
        q: this.q || null,
        year: this.year(),
        month: this.month(),
        period: this.period(),
      },
    });
  }

  apply(): void {
    this.load(1);
  }

  loadMore(): void {
    this.load(this.page() + 1);
  }

  submitSearch(event: Event): void {
    event.preventDefault();
    this.sync();
  }

  setPeriod(value: JournalPeriod | null): void {
    if (this.period() === value) return;
    this.period.set(value);
    this.sync();
  }

  /** Месяц принадлежит году, поэтому при смене года выбранный месяц снимаем. */
  toggleYear(value: number): void {
    const next = this.year() === value ? null : value;
    this.year.set(next);
    this.month.set(null);
    this.sync();
  }

  toggleMonth(value: number): void {
    this.month.set(this.month() === value ? null : value);
    this.sync();
  }

  reset(): void {
    this.q = '';
    this.year.set(null);
    this.month.set(null);
    this.period.set(null);
    this.sync();
  }

  hasFilters(): boolean {
    return !!(this.q || this.year() !== null || this.month() !== null || this.period() !== null);
  }

  periodLabel(): string {
    const value = this.period();
    return value ? JOURNAL_PERIOD_LABELS[value] : '';
  }

  monthLabel(): string {
    const value = this.month();
    if (value === null) return '';
    return this.months().find((m) => m.month === value)?.label ?? '';
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

  downloadLabel(issue: JournalIssueCard): string {
    return `Скачать PDF: ${issue.period_label}, ${issue.period_range}`;
  }

  found(): string {
    const n = this.total() ?? 0;
    return `В журнале ${pluralize(n, 'выпуск', 'выпуска', 'выпусков')}`;
  }

  emptyTitle(): string {
    if (this.hasFilters()) {
      return this.q ? `По запросу «${this.q}» выпусков нет` : 'Под такие условия выпусков нет';
    }
    return 'Журнал пока не вышел';
  }

  emptyHint(): string {
    if (this.hasFilters()) {
      return 'Попробуйте снять условия — возможно, выпуск вышел в другом месяце или другого типа.';
    }
    return this.publishedTotal() === 0
      ? 'Первый гайд редакция соберёт в ближайшие недели. Пока можно посмотреть каталог рецептов.'
      : 'Выпуски появятся здесь сразу после публикации.';
  }
}
