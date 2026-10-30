import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';

import { ApiService, ContestFilters } from '@shared/services/api.service';
import { ContestCard, ContestKind, CONTEST_KIND_LABELS } from '@shared/models/api.models';
import { formatDate, pluralize } from '@shared/utils';
import { SeoService } from '../services/seo.service';

@Component({
    selector: 'app-contests',
    imports: [RouterLink, FormsModule],
    template: `
    <div class="wrap band">
      <h1>Конкурсы</h1>
      <p class="muted sub">
        {{ total() !== null ? found() : 'Загружаем…' }}
      </p>

      <div class="tabs" role="group" aria-label="Какие конкурсы показать">
        <button class="tab" type="button" [class.on]="scope === 'active'"
                [attr.aria-pressed]="scope === 'active'" (click)="setScope('active')">
          Идут сейчас
        </button>
        <button class="tab" type="button" [class.on]="scope === 'archive'"
                [attr.aria-pressed]="scope === 'archive'" (click)="setScope('archive')">
          Архив
        </button>
      </div>

      <div class="bar">
        <form class="find" (submit)="submitSearch($event)">
          <label class="fl" for="cq">Поиск по названию
            <input id="cq" type="search" name="cq" [(ngModel)]="q" placeholder="Например, тыква">
          </label>
          <button class="btn btn-ghost btn-sm search-go" type="submit">Найти</button>
        </form>

        <div class="kinds">
          <span class="muted label">Тип работ</span>
          <div class="row">
            @for (k of kinds; track k.value) {
              <button class="toggle" type="button" [class.on]="kind.includes(k.value)"
                      [attr.aria-pressed]="kind.includes(k.value)" (click)="toggleKind(k.value)">
                {{ k.label }}
              </button>
            }
          </div>
        </div>
      </div>

      @if (hasFilters()) {
        <div class="row applied">
          @if (q) { <span class="chip chip-g">{{ q }}</span> }
          @for (k of kind; track k) { <span class="chip chip-g">{{ kindLabel(k) }}</span> }
          <button class="reset" type="button" (click)="reset()">Сбросить условия</button>
        </div>
      }

      @if (loading()) {
        <div class="loading"><div class="spinner"></div></div>
      } @else if (items().length) {
        <div class="grid cols">
          @for (c of items(); track c.slug) {
            <a class="card tile" [routerLink]="['/contests', c.slug]">
              <div class="shot">
                @if (c.cover) {
                  <img [src]="c.cover.url" [alt]="c.cover.alt || c.title" loading="lazy">
                } @else {
                  <div class="ph" aria-hidden="true">
                    <svg width="30" height="30" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1.3">
                      <path d="M7 4h10v5a5 5 0 0 1-10 0z" /><path d="M12 14v4" />
                      <path d="M8.5 20h7" /><path d="M7 5H4.5v1.5a3 3 0 0 0 3 3" />
                      <path d="M17 5h2.5v1.5a3 3 0 0 1-3 3" />
                    </svg>
                  </div>
                }
              </div>

              <div class="row tags">
                <span class="chip chip-g">{{ c.phase_label }}</span>
                <span class="chip">{{ c.kind_label }}</span>
              </div>

              <h2 class="name">{{ c.title }}</h2>
              @if (c.lead) { <p class="lead muted">{{ c.lead }}</p> }

              <div class="facts">
                @if (c.prize) {
                  <div><span class="k">Приз</span><span class="v">{{ c.prize }}</span></div>
                }
                <div>
                  <span class="k">Работы</span>
                  <span class="v tnum">{{ entriesLabel(c.entries_count) }}</span>
                </div>
                @if (deadlineLabel(c); as d) {
                  <div><span class="k">{{ d.title }}</span><span class="v">{{ d.date }}</span></div>
                }
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
          <h2>Не удалось загрузить конкурсы</h2>
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
            @if (scope === 'active') {
              <button class="btn btn-ghost btn-sm" type="button" (click)="setScope('archive')">
                Смотреть архив
              </button>
            } @else {
              <button class="btn btn-ghost btn-sm" type="button" (click)="setScope('active')">
                Что идёт сейчас
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
    .sub { margin: 6px 0 20px; }
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
    .find { display: flex; align-items: flex-end; gap: 10px; flex: 1 1 320px; min-width: 0; }
    .find label { flex: 1 1 auto; min-width: 0; }
    .search-go { min-height: 44px; }
    .kinds { display: flex; flex-direction: column; gap: 8px; }
    .kinds .label { font-size: 13px; font-weight: 700; }
    .toggle {
      min-height: 44px; padding: 0 16px; border-radius: 999px;
      border: 1px solid var(--line); background: var(--surface); color: var(--muted); font-weight: 600;
    }
    .toggle.on { background: var(--green-soft); border-color: var(--green); color: var(--green-ink); }
    .applied { align-items: center; margin-top: 16px; }
    .reset { border: 0; background: none; color: var(--brick); font-weight: 600; min-height: 44px; padding: 0 4px; }
    .cols { grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); margin-top: 24px; }
    .tile { gap: 12px; padding: 14px; }
    .shot { height: 190px; border-radius: 14px; overflow: hidden; background: var(--sunken); display: grid; place-items: center; }
    .shot img { width: 100%; height: 100%; object-fit: cover; }
    .ph { width: 100%; height: 100%; background: var(--green-soft); color: var(--green-ink); display: grid; place-items: center; }
    .tags { padding: 0 2px; }
    .name { font-size: 23px; line-height: 1.2; padding: 0 2px; }
    .lead { margin: 0; padding: 0 2px; font-size: 14px; line-height: 1.55; }
    .facts { display: flex; flex-direction: column; gap: 8px; margin-top: auto; padding: 12px 2px 2px; border-top: 1px solid var(--line); }
    .facts > div { display: flex; justify-content: space-between; gap: 12px; align-items: baseline; }
    .facts .k { font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); }
    .facts .v { font-size: 14px; font-weight: 700; text-align: right; }
    .loading { display: grid; place-items: center; min-height: 240px; }
    .more { display: flex; justify-content: center; margin-top: 28px; }
    .center { justify-content: center; margin-top: 14px; }
    .empty { margin-top: 24px; }
  `]
})
export class ContestsPage implements OnInit {
  /** Номер запроса: ответ, отставший от нового отбора, игнорируем,
   *  иначе он перезапишет свежий список устаревшим. */
  private loadSeq = 0;

  private api = inject(ApiService);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private seo = inject(SeoService);

  readonly kinds: { value: ContestKind; label: string }[] = [
    { value: 'photo', label: CONTEST_KIND_LABELS.photo },
    { value: 'video', label: CONTEST_KIND_LABELS.video },
    { value: 'recipe', label: CONTEST_KIND_LABELS.recipe },
  ];

  scope: 'active' | 'archive' = 'active';
  q = '';
  kind: string[] = [];

  readonly items = signal<ContestCard[]>([]);
  readonly total = signal<number | null>(null);
  readonly page = signal(1);
  readonly lastPage = signal(1);
  readonly loading = signal(true);
  readonly failed = signal(false);

  ngOnInit(): void {
    this.seo.apply({
      title: 'Конкурсы',
      description: 'Конкурсы «Food Guru»: присылайте фото, видео и рецепты, голосуйте за работы читателей.',
      path: '/contests',
    });

    // Вкладка, поиск и тип работ живут в адресе: ссылку на отбор можно переслать.
    this.route.queryParams.subscribe((params) => {
      this.scope = params['scope'] === 'archive' ? 'archive' : 'active';
      this.q = params['q'] ?? '';

      const kind = params['kind'];
      this.kind = kind === undefined || kind === null ? [] : Array.isArray(kind) ? [...kind] : [kind];

      this.load(1);
    });
  }

  private filters(page: number): ContestFilters {
    return {
      q: this.q || undefined,
      kind: this.kind.length ? this.kind : undefined,
      scope: this.scope,
      page,
    };
  }

  private load(page: number): void {
    this.loading.set(true);
    this.failed.set(false);

    const seq = ++this.loadSeq;

    this.api.contests(this.filters(page)).subscribe({
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
        scope: this.scope === 'active' ? null : this.scope,
        q: this.q || null,
        kind: this.kind.length ? this.kind : null,
      },
    });
  }

  apply(): void {
    this.load(1);
  }

  loadMore(): void {
    this.load(this.page() + 1);
  }

  setScope(scope: 'active' | 'archive'): void {
    if (this.scope === scope) return;
    this.scope = scope;
    this.sync();
  }

  submitSearch(event: Event): void {
    event.preventDefault();
    this.sync();
  }

  toggleKind(value: string): void {
    const i = this.kind.indexOf(value);
    this.kind = i === -1 ? [...this.kind, value] : this.kind.filter((k) => k !== value);
    this.sync();
  }

  reset(): void {
    this.q = '';
    this.kind = [];
    this.sync();
  }

  hasFilters(): boolean {
    return !!(this.q || this.kind.length);
  }

  kindLabel(value: string): string {
    return this.kinds.find((k) => k.value === value)?.label ?? value;
  }

  entriesLabel(n: number): string {
    return pluralize(n, 'работа', 'работы', 'работ');
  }

  found(): string {
    const n = this.total() ?? 0;
    return this.scope === 'archive'
      ? `В архиве ${pluralize(n, 'конкурс', 'конкурса', 'конкурсов')}`
      : `Сейчас ${pluralize(n, 'конкурс', 'конкурса', 'конкурсов')}`;
  }

  /** Подпись к дедлайну зависит от фазы: до одного срока работы, до другого голоса. */
  deadlineLabel(c: ContestCard): { title: string; date: string } | null {
    if (!c.deadline_at) return null;

    const titles: Record<ContestCard['phase'], string | null> = {
      announced: 'Старт приёма',
      submissions: 'Работы до',
      voting: 'Голосование до',
      judging: 'Итоги',
      finished: null,
    };

    const title = titles[c.phase];
    if (!title) return null;

    return { title, date: formatDate(c.deadline_at) };
  }

  emptyTitle(): string {
    if (this.q) return `По запросу «${this.q}» конкурсов нет`;
    return this.scope === 'archive' ? 'Архив пока пуст' : 'Сейчас конкурсов нет';
  }

  emptyHint(): string {
    if (this.hasFilters()) {
      return 'Попробуйте снять условия — возможно, конкурс объявлен с другим типом работ.';
    }
    return this.scope === 'archive'
      ? 'Как только у конкурса объявят итоги, он переедет сюда вместе с работами победителей.'
      : 'Новый конкурс объявляют раз в несколько недель. Пока можно посмотреть архив прошлых работ.';
  }
}
