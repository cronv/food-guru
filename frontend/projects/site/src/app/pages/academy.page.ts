import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';

import { AcademyFilters, ApiService } from '@shared/services/api.service';
import {
  AcademyCard, AcademyFormat, AcademyLevel,
  ACADEMY_FORMAT_LABELS, ACADEMY_LEVEL_LABELS,
} from '@shared/models/api.models';
import { pluralize } from '@shared/utils';
import { SeoService } from '../services/seo.service';

@Component({
    selector: 'app-academy',
    imports: [RouterLink, FormsModule],
    template: `
    <div class="wrap band">
      <h1>Кулинарная академия</h1>
      <p class="muted sub">
        Разборы техник в прямом эфире и видеоуроки, которые можно смотреть когда удобно.
      </p>
      <a class="club-promo" routerLink="/club">
        <span><strong>Закрытая академия</strong> — мастер-классы шефов, ресторанные рецепты и платные книги по подписке</span>
        <span aria-hidden="true">→</span>
      </a>

      <div class="tabs" role="group" aria-label="Что показать">
        <button class="tab" type="button" [class.on]="scope === 'upcoming'"
                [attr.aria-pressed]="scope === 'upcoming'" (click)="setScope('upcoming')">
          Расписание
        </button>
        <button class="tab" type="button" [class.on]="scope === 'archive'"
                [attr.aria-pressed]="scope === 'archive'" (click)="setScope('archive')">
          Записи
        </button>
      </div>

      <div class="bar">
        <form class="find" (submit)="submitSearch($event)">
          <label class="fl" for="aq">Поиск по названию
            <input id="aq" type="search" name="aq" [(ngModel)]="q" placeholder="Например, тесто">
          </label>
          <button class="btn btn-ghost btn-sm search-go" type="submit">Найти</button>
        </form>

        <div class="picks">
          <div class="group">
            <span class="muted label">Формат</span>
            <div class="row">
              @for (f of formats; track f.value) {
                <button class="toggle" type="button" [class.on]="format.includes(f.value)"
                        [attr.aria-pressed]="format.includes(f.value)" (click)="toggleFormat(f.value)">
                  {{ f.label }}
                </button>
              }
            </div>
          </div>

          <div class="group">
            <span class="muted label">Уровень</span>
            <div class="row">
              @for (l of levels; track l.value) {
                <button class="toggle" type="button" [class.on]="level.includes(l.value)"
                        [attr.aria-pressed]="level.includes(l.value)" (click)="toggleLevel(l.value)">
                  {{ l.label }}
                </button>
              }
            </div>
          </div>
        </div>
      </div>

      @if (hasFilters()) {
        <div class="row applied">
          @if (q) { <span class="chip chip-g">{{ q }}</span> }
          @for (f of format; track f) { <span class="chip chip-g">{{ formatLabel(f) }}</span> }
          @for (l of level; track l) { <span class="chip chip-g">{{ levelLabel(l) }}</span> }
          <button class="reset" type="button" (click)="reset()">Сбросить условия</button>
        </div>
      }

      <p class="muted count">{{ total() !== null ? found() : 'Загружаем…' }}</p>

      @if (loading()) {
        <div class="loading"><div class="spinner"></div></div>
      } @else if (items().length) {
        <div class="grid cols">
          @for (s of items(); track s.slug) {
            <a class="card tile" [routerLink]="['/academy', s.slug]">
              <div class="shot">
                @if (s.cover) {
                  <img [src]="s.cover.url" [alt]="s.cover.alt || s.title" loading="lazy">
                } @else {
                  <div class="ph" aria-hidden="true">
                    <svg width="30" height="30" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1.3">
                      <rect x="3" y="5" width="18" height="12" rx="2.5" />
                      <path d="m10.5 9 4 3-4 3z" /><path d="M8 20h8" />
                    </svg>
                  </div>
                }
                @if (s.is_live_now) { <span class="live">Идёт сейчас</span> }
              </div>

              <div class="row tags">
                <span class="chip chip-g">{{ s.format_label }}</span>
                <span class="chip">{{ s.level_label }}</span>
                @if (s.has_record && !s.is_upcoming) { <span class="chip">Есть запись</span> }
              </div>

              <h2 class="name">{{ s.title }}</h2>
              @if (s.lead) { <p class="lead muted">{{ s.lead }}</p> }

              <div class="facts">
                @if (s.host_name) {
                  <div>
                    <span class="k">Ведёт</span>
                    <span class="v">{{ s.host_name }}</span>
                  </div>
                }
                @if (s.starts_at) {
                  <div><span class="k">Начало</span><span class="v">{{ dateTime(s.starts_at) }}</span></div>
                }
                @if (s.duration_minutes) {
                  <div><span class="k">Длительность</span><span class="v tnum">{{ s.duration_minutes }} минут</span></div>
                }
                <div>
                  <span class="k">Записались</span>
                  <span class="v tnum">{{ peopleLabel(s.registrations_count) }}</span>
                </div>
              </div>
            </a>
          }
        </div>

        @if (page() < lastPage()) {
          <div class="more">
            <button class="btn btn-ghost" type="button" (click)="loadMore()">Показать ещё</button>
          </div>
        }
      } @else if (failed()) {
        <div class="empty">
          <h2>Не удалось загрузить расписание</h2>
          <p class="muted">Похоже, связь с сервером пропала. Обновите страницу через минуту.</p>
          <button class="btn btn-green" type="button" (click)="apply()">Попробовать снова</button>
        </div>
      } @else {
        <div class="empty">
          <h2>{{ emptyTitle() }}</h2>
          <p class="muted">{{ emptyHint() }}</p>
          <div class="row center">
            @if (hasFilters()) {
              <button class="btn btn-green btn-sm" type="button" (click)="reset()">Сбросить условия</button>
            }
            @if (scope === 'upcoming') {
              <button class="btn btn-ghost btn-sm" type="button" (click)="setScope('archive')">
                Смотреть записи
              </button>
            } @else {
              <button class="btn btn-ghost btn-sm" type="button" (click)="setScope('upcoming')">
                Открыть расписание
              </button>
            }
            <a class="btn btn-ghost btn-sm" routerLink="/recipes">К каталогу рецептов</a>
          </div>
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
    .picks { display: flex; flex-wrap: wrap; gap: 18px; }
    .group { display: flex; flex-direction: column; gap: 8px; }
    .group .label { font-size: 13px; font-weight: 700; }
    .toggle {
      min-height: 44px; padding: 0 16px; border-radius: 999px;
      border: 1px solid var(--line); background: var(--surface); color: var(--muted); font-weight: 600;
    }
    .toggle.on { background: var(--green-soft); border-color: var(--green); color: var(--green-ink); }
    .applied { align-items: center; margin-top: 16px; }
    .reset { border: 0; background: none; color: var(--brick); font-weight: 600; min-height: 44px; padding: 0 4px; }
    .count { margin: 18px 0 0; font-size: 14px; }
    .cols { grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); margin-top: 18px; }
    .tile { gap: 12px; padding: 14px; }
    .shot {
      position: relative; height: 180px; border-radius: 14px; overflow: hidden;
      background: var(--sunken); display: grid; place-items: center;
    }
    .shot img { width: 100%; height: 100%; object-fit: cover; }
    .ph { width: 100%; height: 100%; background: var(--sunken); color: var(--muted); display: grid; place-items: center; }
    .live {
      position: absolute; left: 10px; top: 10px; padding: 5px 12px; border-radius: 999px;
      background: var(--brick); color: var(--on-accent); font-size: 12px; font-weight: 700;
    }
    .tags { padding: 0 2px; }
    .name { font-size: 22px; line-height: 1.2; padding: 0 2px; }
    .lead { margin: 0; padding: 0 2px; font-size: 14px; line-height: 1.55; }
    .club-promo {
      display: flex; align-items: center; justify-content: space-between; gap: 16px; margin: 14px 0 6px;
      padding: 14px 18px; border-radius: 16px; background: var(--panel); color: var(--panel-ink);
    }
    .club-promo strong { color: #E8C77A; }
    .club-promo:hover { color: #FFFBF4; }
    .facts {
      display: flex; flex-direction: column; gap: 8px; margin-top: auto;
      padding: 12px 2px 2px; border-top: 1px solid var(--line);
    }
    .facts > div { display: flex; justify-content: space-between; gap: 12px; align-items: baseline; }
    .facts .k { font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); }
    .facts .v { font-size: 14px; font-weight: 700; text-align: right; }
    .loading { display: grid; place-items: center; min-height: 240px; }
    .more { display: flex; justify-content: center; margin-top: 28px; }
    .center { justify-content: center; margin-top: 14px; }
    .empty { margin-top: 20px; }
  `]
})
export class AcademyPage implements OnInit {
  /** Номер запроса: ответ, отставший от нового отбора, игнорируем,
   *  иначе он перезапишет свежий список устаревшим. */
  private loadSeq = 0;

  private api = inject(ApiService);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private seo = inject(SeoService);

  readonly formats: { value: AcademyFormat; label: string }[] = [
    { value: 'webinar', label: ACADEMY_FORMAT_LABELS.webinar },
    { value: 'stream', label: ACADEMY_FORMAT_LABELS.stream },
    { value: 'lesson', label: ACADEMY_FORMAT_LABELS.lesson },
  ];

  readonly levels: { value: AcademyLevel; label: string }[] = [
    { value: 'basic', label: ACADEMY_LEVEL_LABELS.basic },
    { value: 'medium', label: ACADEMY_LEVEL_LABELS.medium },
    { value: 'advanced', label: ACADEMY_LEVEL_LABELS.advanced },
  ];

  scope: 'upcoming' | 'archive' = 'upcoming';
  q = '';
  format: string[] = [];
  level: string[] = [];

  readonly items = signal<AcademyCard[]>([]);
  readonly total = signal<number | null>(null);
  readonly page = signal(1);
  readonly lastPage = signal(1);
  readonly loading = signal(true);
  readonly failed = signal(false);

  ngOnInit(): void {
    this.seo.apply({
      title: 'Кулинарная академия',
      description: 'Расписание эфиров и видеоуроков «Food Guru»: техники, разборы и ответы ведущих.',
      path: '/academy',
    });

    // Вкладка и фильтры живут в адресе — ссылкой на отбор можно поделиться.
    this.route.queryParams.subscribe((params) => {
      this.scope = params['scope'] === 'archive' ? 'archive' : 'upcoming';
      this.q = params['q'] ?? '';
      this.format = this.asList(params['format']);
      this.level = this.asList(params['level']);

      this.load(1);
    });
  }

  /** Один параметр в адресе приходит строкой, несколько — массивом. */
  private asList(value: unknown): string[] {
    if (value === undefined || value === null) return [];
    return Array.isArray(value) ? [...(value as string[])] : [String(value)];
  }

  private filters(page: number): AcademyFilters {
    return {
      q: this.q || undefined,
      format: this.format.length ? this.format : undefined,
      level: this.level.length ? this.level : undefined,
      scope: this.scope,
      page,
    };
  }

  private load(page: number): void {
    this.loading.set(true);
    this.failed.set(false);

    const seq = ++this.loadSeq;

    this.api.academy(this.filters(page)).subscribe({
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

  private sync(): void {
    this.router.navigate([], {
      relativeTo: this.route,
      queryParams: {
        scope: this.scope === 'upcoming' ? null : this.scope,
        q: this.q || null,
        format: this.format.length ? this.format : null,
        level: this.level.length ? this.level : null,
      },
    });
  }

  apply(): void {
    this.load(1);
  }

  loadMore(): void {
    this.load(this.page() + 1);
  }

  setScope(scope: 'upcoming' | 'archive'): void {
    if (this.scope === scope) return;
    this.scope = scope;
    this.sync();
  }

  submitSearch(event: Event): void {
    event.preventDefault();
    this.sync();
  }

  toggleFormat(value: string): void {
    this.format = this.format.includes(value)
      ? this.format.filter((f) => f !== value)
      : [...this.format, value];
    this.sync();
  }

  toggleLevel(value: string): void {
    this.level = this.level.includes(value)
      ? this.level.filter((l) => l !== value)
      : [...this.level, value];
    this.sync();
  }

  reset(): void {
    this.q = '';
    this.format = [];
    this.level = [];
    this.sync();
  }

  hasFilters(): boolean {
    return !!(this.q || this.format.length || this.level.length);
  }

  formatLabel(value: string): string {
    return this.formats.find((f) => f.value === value)?.label ?? value;
  }

  levelLabel(value: string): string {
    return this.levels.find((l) => l.value === value)?.label ?? value;
  }

  peopleLabel(n: number): string {
    return pluralize(n, 'человек', 'человека', 'человек');
  }

  found(): string {
    const n = this.total() ?? 0;
    return this.scope === 'archive'
      ? `В записях ${pluralize(n, 'занятие', 'занятия', 'занятий')}`
      : `В расписании ${pluralize(n, 'занятие', 'занятия', 'занятий')}`;
  }

  /** 3 ноября, 19:00 — в часовом поясе читателя. */
  dateTime(iso: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    return d.toLocaleString('ru-RU', {
      day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit',
    });
  }

  emptyTitle(): string {
    if (this.q) return `По запросу «${this.q}» занятий нет`;
    return this.scope === 'archive' ? 'Записей пока нет' : 'В расписании пока пусто';
  }

  emptyHint(): string {
    if (this.hasFilters()) {
      return 'Попробуйте снять условия — возможно, занятие идёт в другом формате или на другом уровне.';
    }
    return this.scope === 'archive'
      ? 'Записи появляются здесь после эфира. Пока можно посмотреть, что в расписании.'
      : 'Новые эфиры объявляют за неделю до начала. Пока можно посмотреть записи прошлых занятий.';
  }
}
