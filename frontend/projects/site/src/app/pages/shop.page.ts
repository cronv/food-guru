import { Component, DestroyRef, OnInit, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Subject, debounceTime, distinctUntilChanged } from 'rxjs';

import { Product, ProductFacets, ProductFilters, rub } from '@shared/models/commerce.models';
import { CommerceService } from '@shared/services/commerce.service';
import { pluralize } from '@shared/utils';
import { CheckGroupComponent, RangeComponent } from '../components/catalog-filters.component';
import { CartService } from '../services/cart.service';
import { SeoService } from '../services/seo.service';
import { ToastService } from '../services/toast.service';

type Sort = NonNullable<ProductFilters['sort']>;

const SORTS: { value: Sort; label: string }[] = [
  { value: 'popular', label: 'Сначала популярные' },
  { value: 'price_asc', label: 'Сначала дешевле' },
  { value: 'price_desc', label: 'Сначала дороже' },
  { value: 'discount', label: 'По размеру скидки' },
  { value: 'new', label: 'Новинки' },
  { value: 'title', label: 'По названию' },
];

/**
 * Магазин: поиск, сортировка и фильтры. Всё состояние — в адресе страницы
 * (?q=нож&material=Сталь&sort=price_asc), поэтому подборкой можно поделиться,
 * а «Назад» в браузере возвращает прежние фильтры.
 */
@Component({
    selector: 'app-shop',
    imports: [RouterLink, CheckGroupComponent, RangeComponent],
    template: `
    <div class="wrap band">
      <h1>Магазин</h1>
      <p class="muted lead">Посуда и инструменты, которыми мы готовим сами. Оплата с баланса кошелька.</p>

      <div class="toolbar">
        <label class="search">
          <span class="sr">Поиск по названию</span>
          <input type="search" placeholder="Поиск по названию" [value]="f().q ?? ''" (input)="search$.next($any($event.target).value)">
        </label>
        <label class="sort">
          <span class="muted">Сортировка</span>
          <select [value]="f().sort ?? 'popular'" (change)="set({ sort: $any($event.target).value })">
            @for (s of sorts; track s.value) { <option [value]="s.value">{{ s.label }}</option> }
          </select>
        </label>
        <button class="btn btn-line toggle" type="button" [attr.aria-expanded]="panel()" aria-controls="shop-filters"
                (click)="panel.set(!panel())">Фильтры @if (activeCount()) { · {{ activeCount() }} }</button>
      </div>

      <div class="layout">
        <aside class="filters" id="shop-filters" [class.open]="panel()" aria-label="Фильтры">
          @if (facets(); as fc) {
            <app-range title="Цена, ₽" [from]="rubOrNull(f().price_min)" [to]="rubOrNull(f().price_max)"
                       [minHint]="fc.price.min / 100" [maxHint]="fc.price.max / 100"
                       (changed)="set({ price_min: kop($event.from), price_max: kop($event.to) })" />
            <label class="opt"><input type="checkbox" [checked]="!!f().in_stock" (change)="set({ in_stock: $any($event.target).checked || undefined })"> Только в наличии</label>
            <app-check-group title="Категория" [options]="fc.categories" [selected]="f().category ?? []" (changed)="set({ category: $event })" />
            <app-check-group title="Материал" [options]="fc.materials" [selected]="f().material ?? []" (changed)="set({ material: $event })" />
            <app-check-group title="Страна производства" [options]="fc.countries" [selected]="f().country ?? []" (changed)="set({ country: $event })" />
            <app-check-group title="Цвет" [options]="fc.colors" [selected]="f().color ?? []" [swatches]="true" (changed)="set({ color: $event })" />
            @if (activeCount()) {
              <button class="btn btn-ghost btn-sm" type="button" (click)="reset()">Сбросить фильтры</button>
            }
          }
        </aside>

        <section class="results" aria-live="polite">
          <p class="muted count">{{ found() }}</p>
          @if (loading() && !products().length) {
            <div class="state"><div class="spinner"></div></div>
          } @else if (products().length) {
            <div class="goods" [class.dim]="loading()">
              @for (p of products(); track p.slug) {
                <article class="good" [class.out]="!p.in_stock">
                  <a class="shot" [routerLink]="['/shop', p.slug]">
                    @if (p.cover) { <img [src]="p.cover.url" [alt]="p.cover.alt || p.title" loading="lazy"> }
                    @if (p.old_price) { <span class="sale">−{{ discount(p) }}%</span> }
                  </a>
                  <a class="title" [routerLink]="['/shop', p.slug]">{{ p.title }}</a>
                  @if (p.material || p.country) {
                    <span class="muted props">{{ props(p) }}</span>
                  }
                  <div class="price">
                    <span class="tnum now">{{ rub(p.price) }}</span>
                    @if (p.old_price) { <s class="muted tnum">{{ rub(p.old_price) }}</s> }
                  </div>
                  @if (!p.in_stock) {
                    <span class="muted stock">Нет в наличии</span>
                  } @else if (p.stock_left) {
                    <span class="stock low">Осталось {{ p.stock_left }} шт.</span>
                  }
                  @if (p.in_stock) {
                    @if (cart.quantityOf(p.id)) {
                      <a class="btn btn-ghost btn-sm" routerLink="/cart">В корзине · {{ cart.quantityOf(p.id) }}</a>
                    } @else {
                      <button class="btn btn-green btn-sm" type="button" (click)="add(p)">В корзину</button>
                    }
                  }
                </article>
              }
            </div>
          } @else {
            <div class="state empty">
              <p>По этим условиям ничего не нашлось.</p>
              <button class="btn btn-ghost" type="button" (click)="reset()">Сбросить фильтры</button>
            </div>
          }
        </section>
      </div>
    </div>
  `,
    styles: [`
    h1 { font-size: clamp(28px, 4vw, 38px); }
    .lead { margin: 8px 0 18px; max-width: 60ch; }
    .toolbar { display: flex; flex-wrap: wrap; gap: 10px 16px; align-items: center; margin-bottom: 18px; }
    .search { flex: 1 1 260px; }
    .search input { border-radius: 999px; }
    .sort { display: flex; align-items: center; gap: 8px; }
    .sort select { width: auto; min-width: 210px; }
    .toggle { display: none; }
    .layout { display: grid; grid-template-columns: 240px 1fr; gap: 24px; align-items: start; }
    .filters {
      display: flex; flex-direction: column; gap: 20px; padding: 18px; border-radius: 18px;
      background: var(--surface); border: 1px solid var(--line); position: sticky; top: 84px;
    }
    .opt { display: flex; align-items: center; gap: 10px; font-size: 14px; cursor: pointer; }
    .count { margin: 0 0 12px; font-size: 14px; }
    .goods { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 16px; transition: opacity .15s; }
    .goods.dim { opacity: .55; }
    .good { display: flex; flex-direction: column; gap: 6px; padding: 12px; border-radius: 18px; background: var(--surface); border: 1px solid var(--line); }
    .good.out .shot { opacity: .55; }
    .shot { position: relative; aspect-ratio: 1; border-radius: 12px; overflow: hidden; background: var(--sunken); }
    .shot img { width: 100%; height: 100%; object-fit: cover; }
    .sale { position: absolute; top: 8px; left: 8px; padding: 2px 8px; border-radius: 999px; background: var(--brick); color: var(--on-accent); font-size: 12px; font-weight: 700; }
    .title { font-weight: 700; color: var(--ink); line-height: 1.3; }
    .props { font-size: 12px; }
    .price { display: flex; align-items: baseline; gap: 8px; }
    .now { font-size: 18px; font-weight: 700; }
    .stock { font-size: 13px; }
    .low { color: var(--brick-ink); font-weight: 600; }
    .good .btn { margin-top: auto; }
    .state { display: grid; place-items: center; gap: 12px; min-height: 220px; text-align: center; }
    .sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
    @media (max-width: 860px) {
      .layout { grid-template-columns: 1fr; }
      .toggle { display: inline-flex; }
      .filters { display: none; position: static; }
      .filters.open { display: flex; }
      .sort select { min-width: 0; }
    }
    @media (prefers-reduced-motion: reduce) { .goods { transition: none; } }
  `]
})
export class ShopPage implements OnInit {
  private commerce = inject(CommerceService);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private seo = inject(SeoService);
  private toasts = inject(ToastService);
  private destroyRef = inject(DestroyRef);
  readonly cart = inject(CartService);
  readonly rub = rub;
  readonly sorts = SORTS;

  readonly f = signal<ProductFilters>({});
  readonly products = signal<Product[]>([]);
  readonly facets = signal<ProductFacets | null>(null);
  readonly loading = signal(true);
  readonly panel = signal(false);
  readonly search$ = new Subject<string>();
  /** Номер запроса: ответ на устаревший фильтр не должен перезаписать свежий. */
  private seq = 0;

  readonly activeCount = computed(() => {
    const f = this.f();
    return (f.category?.length ?? 0) + (f.material?.length ?? 0) + (f.country?.length ?? 0) + (f.color?.length ?? 0)
      + (f.price_min != null || f.price_max != null ? 1 : 0) + (f.in_stock ? 1 : 0);
  });

  readonly found = computed(() => this.loading() ? 'Ищем…' : `Найдено ${pluralize(this.products().length, 'товар', 'товара', 'товаров')}`);

  ngOnInit(): void {
    this.seo.apply({ title: 'Магазин', description: 'Ножи, доски, посуда и инструменты для домашней кухни.', path: '/shop' });

    this.search$.pipe(debounceTime(300), distinctUntilChanged(), takeUntilDestroyed(this.destroyRef))
      .subscribe((q) => this.set({ q: q.trim() || undefined }));

    // Источник правды — адрес: и первый заход, и «Назад» в браузере.
    this.route.queryParamMap.pipe(takeUntilDestroyed(this.destroyRef)).subscribe((p) => {
      const list = (k: string) => p.getAll(k).filter(Boolean);
      const num = (k: string) => (p.get(k) !== null && p.get(k) !== '' ? Number(p.get(k)) : undefined);
      this.f.set({
        q: p.get('q') || undefined,
        category: list('category'),
        material: list('material'),
        country: list('country'),
        color: list('color'),
        price_min: num('price_min'),
        price_max: num('price_max'),
        in_stock: p.get('in_stock') === '1' || undefined,
        sort: (p.get('sort') as Sort) || undefined,
      });
      this.load();
    });
  }

  set(patch: Partial<ProductFilters>): void {
    const next = { ...this.f(), ...patch };
    this.router.navigate([], {
      relativeTo: this.route,
      replaceUrl: true,
      queryParams: {
        q: next.q || null,
        category: next.category?.length ? next.category : null,
        material: next.material?.length ? next.material : null,
        country: next.country?.length ? next.country : null,
        color: next.color?.length ? next.color : null,
        price_min: next.price_min ?? null,
        price_max: next.price_max ?? null,
        in_stock: next.in_stock ? 1 : null,
        sort: next.sort && next.sort !== 'popular' ? next.sort : null,
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

  add(p: Product): void {
    this.cart.add(p);
    this.toasts.show(`«${p.title}» в корзине`);
  }

  props(p: Product): string {
    return [p.material, p.country].filter(Boolean).join(' · ');
  }

  discount(p: Product): number {
    return p.old_price ? Math.round((1 - p.price / p.old_price) * 100) : 0;
  }

  private load(): void {
    this.loading.set(true);
    const seq = ++this.seq;
    this.commerce.products(this.f()).subscribe({
      next: (res) => {
        if (seq !== this.seq) return;
        this.products.set(res.data);
        this.facets.set(res.meta.facets);
        this.loading.set(false);
      },
      error: () => {
        if (seq === this.seq) this.loading.set(false);
      },
    });
  }
}
