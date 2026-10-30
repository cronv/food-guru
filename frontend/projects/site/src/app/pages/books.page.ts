import { Component, DestroyRef, OnInit, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Subject, debounceTime, distinctUntilChanged } from 'rxjs';

import { BookCard, BookFacets, BookFilters, rub } from '@shared/models/commerce.models';
import { AuthService } from '@shared/services/auth.service';
import { CommerceService } from '@shared/services/commerce.service';
import { pluralize } from '@shared/utils';
import { CheckGroupComponent, RangeComponent } from '../components/catalog-filters.component';
import { SeoService } from '../services/seo.service';

type Status = NonNullable<BookFilters['status']>;
type Sort = NonNullable<BookFilters['sort']>;

const SORTS: { value: Sort; label: string }[] = [
  { value: 'new', label: 'Сначала новые на сайте' },
  { value: 'year_desc', label: 'Год издания: новее' },
  { value: 'year_asc', label: 'Год издания: старше' },
  { value: 'price_asc', label: 'Сначала дешевле' },
  { value: 'price_desc', label: 'Сначала дороже' },
  { value: 'title', label: 'По названию' },
];

/** Книги: поиск, сортировка, фильтры. Состояние — в адресе, как в магазине. */
@Component({
    selector: 'app-books',
    imports: [RouterLink, CheckGroupComponent, RangeComponent],
    template: `
    <div class="wrap band">
      <h1>Книги</h1>
      <p class="muted lead">Бесплатные книги редакции и платные — от шефов. Часть платных открыта подписчикам закрытой академии.</p>

      <div class="toolbar">
        <label class="search">
          <span class="sr">Поиск по названию</span>
          <input type="search" placeholder="Поиск по названию" [value]="f().q ?? ''" (input)="search$.next($any($event.target).value)">
        </label>
        <label class="sort">
          <span class="muted">Сортировка</span>
          <select [value]="f().sort ?? 'new'" (change)="set({ sort: $any($event.target).value })">
            @for (s of sorts; track s.value) { <option [value]="s.value">{{ s.label }}</option> }
          </select>
        </label>
        <button class="btn btn-line toggle" type="button" [attr.aria-expanded]="panel()" aria-controls="book-filters"
                (click)="panel.set(!panel())">Фильтры @if (activeCount()) { · {{ activeCount() }} }</button>
      </div>

      <div class="layout">
        <aside class="filters" id="book-filters" [class.open]="panel()" aria-label="Фильтры">
          <fieldset class="group">
            <legend>Статус</legend>
            @for (s of statuses; track s.value) {
              @if (s.value !== 'mine' || auth.isAuthenticated()) {
                <label class="opt">
                  <input type="radio" name="status" [checked]="(f().status ?? 'all') === s.value" (change)="set({ status: s.value })">
                  {{ s.label }}
                </label>
              }
            }
          </fieldset>
          @if (facets(); as fc) {
            <app-range title="Цена, ₽" [from]="rubOrNull(f().price_min)" [to]="rubOrNull(f().price_max)"
                       [minHint]="fc.price.min / 100" [maxHint]="fc.price.max / 100"
                       (changed)="set({ price_min: kop($event.from), price_max: kop($event.to) })" />
            <app-range title="Год издания" [from]="f().year_from ?? null" [to]="f().year_to ?? null"
                       [minHint]="fc.years.min" [maxHint]="fc.years.max"
                       (changed)="set({ year_from: $event.from ?? undefined, year_to: $event.to ?? undefined })" />
            <app-check-group title="Автор" [options]="fc.authors" [selected]="f().author ?? []" (changed)="set({ author: $event })" />
            <app-check-group title="Страна" [options]="fc.countries" [selected]="f().country ?? []" (changed)="set({ country: $event })" />
          }
          @if (activeCount()) {
            <button class="btn btn-ghost btn-sm" type="button" (click)="reset()">Сбросить фильтры</button>
          }
        </aside>

        <section aria-live="polite">
          <p class="muted count">{{ found() }}</p>
          @if (loading() && !books().length) {
            <div class="state"><div class="spinner"></div></div>
          } @else if (books().length) {
            <div class="shelf" [class.dim]="loading()">
              @for (b of books(); track b.slug) {
                <a class="book" [routerLink]="['/books', b.slug]">
                  <div class="cover">@if (b.cover) { <img [src]="b.cover.url" [alt]="b.cover.alt || b.title" loading="lazy"> }</div>
                  <div class="badges">
                    @if (b.is_free) {
                      <span class="chip chip-g">Бесплатно</span>
                    } @else if (b.owned) {
                      <span class="chip chip-g">Куплено</span>
                    } @else {
                      <span class="chip price tnum">{{ rub(b.price) }}</span>
                    }
                    @if (b.in_subscription && !b.is_free) { <span class="chip">В подписке</span> }
                  </div>
                  <h2>{{ b.title }}</h2>
                  <p class="muted meta">{{ meta(b) }}</p>
                </a>
              }
            </div>
          } @else {
            <div class="state">
              <p>По этим условиям книг нет.</p>
              <button class="btn btn-ghost" type="button" (click)="reset()">Сбросить фильтры</button>
            </div>
          }
        </section>
      </div>
    </div>
  `,
    styles: [`
    h1 { font-size: clamp(28px, 4vw, 38px); }
    .lead { margin: 8px 0 18px; max-width: 64ch; }
    .toolbar { display: flex; flex-wrap: wrap; gap: 10px 16px; align-items: center; margin-bottom: 18px; }
    .search { flex: 1 1 260px; }
    .search input { border-radius: 999px; }
    .sort { display: flex; align-items: center; gap: 8px; }
    .sort select { width: auto; min-width: 220px; }
    .toggle { display: none; }
    .layout { display: grid; grid-template-columns: 240px 1fr; gap: 24px; align-items: start; }
    .filters {
      display: flex; flex-direction: column; gap: 20px; padding: 18px; border-radius: 18px;
      background: var(--surface); border: 1px solid var(--line); position: sticky; top: 84px;
    }
    .group { border: 0; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 2px; }
    legend { font-weight: 700; margin-bottom: 6px; font-size: 14px; }
    .opt { display: flex; align-items: center; gap: 10px; min-height: 34px; font-size: 14px; cursor: pointer; }
    .count { margin: 0 0 12px; font-size: 14px; }
    .shelf { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 24px 18px; transition: opacity .15s; }
    .shelf.dim { opacity: .55; }
    .book { display: flex; flex-direction: column; gap: 8px; color: var(--ink); }
    .cover { aspect-ratio: 2 / 3; border-radius: 6px 14px 14px 6px; overflow: hidden; background: var(--sunken); box-shadow: 0 10px 24px rgb(42 28 20 / .16); transition: transform .2s; }
    .book:hover .cover { transform: translateY(-4px); }
    .cover img { width: 100%; height: 100%; object-fit: cover; }
    .badges { display: flex; flex-wrap: wrap; gap: 6px; }
    .price { font-weight: 700; }
    h2 { font-family: var(--body); font-size: 16px; font-weight: 700; line-height: 1.3; margin: 0; }
    .meta { margin: 0; font-size: 13px; }
    .state { display: grid; place-items: center; gap: 12px; min-height: 220px; text-align: center; }
    .sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
    @media (max-width: 860px) {
      .layout { grid-template-columns: 1fr; }
      .toggle { display: inline-flex; }
      .filters { display: none; position: static; }
      .filters.open { display: flex; }
      .sort select { min-width: 0; }
    }
    @media (prefers-reduced-motion: reduce) { .cover, .shelf { transition: none; } }
  `]
})
export class BooksPage implements OnInit {
  private commerce = inject(CommerceService);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private seo = inject(SeoService);
  private destroyRef = inject(DestroyRef);
  readonly auth = inject(AuthService);
  readonly rub = rub;
  readonly sorts = SORTS;

  readonly statuses: { value: Status; label: string }[] = [
    { value: 'all', label: 'Все' },
    { value: 'free', label: 'Бесплатные' },
    { value: 'paid', label: 'Платные' },
    { value: 'subscription', label: 'В подписке' },
    { value: 'mine', label: 'Мои' },
  ];

  readonly f = signal<BookFilters>({});
  readonly books = signal<BookCard[]>([]);
  readonly facets = signal<BookFacets | null>(null);
  readonly loading = signal(true);
  readonly panel = signal(false);
  readonly search$ = new Subject<string>();
  private seq = 0;

  readonly activeCount = computed(() => {
    const f = this.f();
    return (f.status && f.status !== 'all' ? 1 : 0) + (f.author?.length ?? 0) + (f.country?.length ?? 0)
      + (f.price_min != null || f.price_max != null ? 1 : 0) + (f.year_from != null || f.year_to != null ? 1 : 0);
  });

  readonly found = computed(() => this.loading() ? 'Ищем…' : `Найдено ${pluralize(this.books().length, 'книга', 'книги', 'книг')}`);

  ngOnInit(): void {
    this.seo.apply({ title: 'Книги', description: 'Бесплатные и платные кулинарные книги: техника, тесто, соусы, заготовки.', path: '/books' });

    this.search$.pipe(debounceTime(300), distinctUntilChanged(), takeUntilDestroyed(this.destroyRef))
      .subscribe((q) => this.set({ q: q.trim() || undefined }));

    this.route.queryParamMap.pipe(takeUntilDestroyed(this.destroyRef)).subscribe((p) => {
      const num = (k: string) => (p.get(k) ? Number(p.get(k)) : undefined);
      this.f.set({
        q: p.get('q') || undefined,
        // filter — прежнее имя параметра, ссылки на /books?filter=free продолжают работать.
        status: ((p.get('status') ?? p.get('filter')) as Status) || undefined,
        price_min: num('price_min'),
        price_max: num('price_max'),
        year_from: num('year_from'),
        year_to: num('year_to'),
        author: p.getAll('author').filter(Boolean),
        country: p.getAll('country').filter(Boolean),
        sort: (p.get('sort') as Sort) || undefined,
      });
      this.load();
    });
  }

  set(patch: Partial<BookFilters>): void {
    const n = { ...this.f(), ...patch };
    this.router.navigate([], {
      relativeTo: this.route,
      replaceUrl: true,
      queryParams: {
        q: n.q || null,
        status: n.status && n.status !== 'all' ? n.status : null,
        price_min: n.price_min ?? null,
        price_max: n.price_max ?? null,
        year_from: n.year_from ?? null,
        year_to: n.year_to ?? null,
        author: n.author?.length ? n.author : null,
        country: n.country?.length ? n.country : null,
        sort: n.sort && n.sort !== 'new' ? n.sort : null,
      },
    });
  }

  reset(): void {
    this.router.navigate([], { relativeTo: this.route, replaceUrl: true, queryParams: {} });
  }

  rubOrNull(kop: number | undefined): number | null {
    return kop == null ? null : kop / 100;
  }

  kop(r: number | null): number | undefined {
    return r == null ? undefined : Math.round(r * 100);
  }

  meta(b: BookCard): string {
    return [b.author_name, b.year, b.country].filter(Boolean).join(' · ');
  }

  private load(): void {
    this.loading.set(true);
    const seq = ++this.seq;
    this.commerce.books(this.f()).subscribe({
      next: (res) => {
        if (seq !== this.seq) return;
        this.books.set(res.data);
        this.facets.set(res.meta.facets);
        this.loading.set(false);
      },
      error: () => {
        if (seq === this.seq) this.loading.set(false);
      },
    });
  }
}
