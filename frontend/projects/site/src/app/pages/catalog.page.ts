import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';

import { ApiService, CatalogFilters } from '@shared/services/api.service';
import { Category, RecipeCard } from '@shared/models/api.models';
import { pluralize } from '@shared/utils';
import { RecipeCardComponent } from '../components/recipe-card.component';
import { SeoService } from '../services/seo.service';

@Component({
    selector: 'app-catalog',
    imports: [RouterLink, FormsModule, RecipeCardComponent],
    template: `
    <div class="wrap band">
      <h1>Все рецепты</h1>
      <p class="muted sub">{{ total() !== null ? found() : 'Загружаем…' }}</p>

      <div class="layout">
        <aside class="filters">
          <div class="head">
            <h2>Фильтры</h2>
            @if (hasFilters()) {
              <button class="reset" type="button" (click)="reset()">Сбросить</button>
            }
          </div>

          <label class="fl">Поиск
            <input type="search" [(ngModel)]="q" (keyup.enter)="apply()" placeholder="Например, тыква">
          </label>

          <fieldset>
            <legend>Категория</legend>
            @for (c of categories(); track c.slug) {
              <label class="check">
                <input type="checkbox" [checked]="category === c.slug"
                       (change)="toggleCategory(c.slug)">
                {{ c.title }}
                @if (c.recipes_count !== undefined) { <span class="muted">· {{ c.recipes_count }}</span> }
              </label>
            }
          </fieldset>

          <label class="fl">Время приготовления: до <span class="tnum">{{ maxTime }}</span> минут
            <input type="range" min="15" max="180" step="5" [(ngModel)]="maxTime" (change)="apply()">
          </label>

          <fieldset>
            <legend>Сложность</legend>
            @for (d of difficulties; track d.value) {
              <label class="check">
                <input type="checkbox" [checked]="difficulty.includes(d.value)"
                       (change)="toggle(difficulty, d.value)">
                {{ d.label }}
              </label>
            }
          </fieldset>

          <fieldset>
            <legend>Диета</legend>
            <label class="check">
              <input type="checkbox" [checked]="diet.includes('vegetarian')"
                     (change)="toggle(diet, 'vegetarian')"> Вегетарианское
            </label>
            <label class="check">
              <input type="checkbox" [checked]="diet.includes('gluten_free')"
                     (change)="toggle(diet, 'gluten_free')"> Без глютена
            </label>
          </fieldset>
        </aside>

        <section class="results">
          <div class="toolbar">
            <div class="row">
              @if (q) { <span class="chip chip-g">{{ q }}</span> }
              @if (category) { <span class="chip chip-g">{{ categoryTitle() }}</span> }
              @if (maxTime < 180) { <span class="chip chip-g">До {{ maxTime }} мин</span> }
            </div>
            <label class="sort muted">Сортировка
              <select [(ngModel)]="sort" (change)="apply()">
                <option value="popular">Сначала популярные</option>
                <option value="new">Сначала новые</option>
                <option value="fast">Быстрые в приготовлении</option>
              </select>
            </label>
          </div>

          @if (loading()) {
            <div class="loading"><div class="spinner"></div></div>
          } @else if (recipes().length) {
            <div class="grid">
              @for (r of recipes(); track r.slug) {
                <app-recipe-card [recipe]="r" />
              }
            </div>

            @if (page() < lastPage()) {
              <div class="more">
                <button class="btn btn-ghost" type="button" (click)="loadMore()">Показать ещё</button>
              </div>
            }
          } @else {
            <div class="empty">
              <h3>{{ q ? 'По запросу «' + q + '» ничего нет' : 'Под такие условия ничего нет' }}</h3>
              <p class="muted">
                {{ q
                  ? 'Возможно, опечатка или такого рецепта пока не написали.'
                  : 'Попробуйте ослабить условия — например, увеличить время приготовления.' }}
              </p>
              <div class="row center">
                <button class="btn btn-green btn-sm" type="button" (click)="reset()">Сбросить фильтры</button>
                <a class="btn btn-ghost btn-sm" routerLink="/collections">Открыть подборки</a>
              </div>
            </div>
          }
        </section>
      </div>
    </div>
  `,
    styles: [`
    h1 { font-size: clamp(28px, 4vw, 40px); }
    .sub { margin: 6px 0 24px; }
    .layout { display: flex; flex-wrap: wrap; gap: 26px; align-items: flex-start; }
    .filters {
      flex: 1 1 250px; min-width: 0;
      background: var(--surface); border: 1px solid var(--line); border-radius: 18px;
      padding: 20px; display: flex; flex-direction: column; gap: 20px;
    }
    .head { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
    .head h2 { font-family: var(--body); font-size: 16px; font-weight: 700; }
    .reset { border: 0; background: none; color: var(--brick); font-weight: 600; padding: 0; }
    fieldset { border: 0; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 8px; }
    legend { padding: 0; font-size: 13px; font-weight: 700; color: var(--muted); }
    /* 40px — зона касания: подпись целиком нажимается пальцем, а не только квадратик. */
    .check { display: flex; align-items: center; gap: 10px; min-height: 40px; font-size: 15px; cursor: pointer; }
    .results { flex: 999 1 540px; min-width: 0; }
    .toolbar {
      display: flex; flex-wrap: wrap; gap: 12px; align-items: center;
      justify-content: space-between; margin-bottom: 18px;
    }
    .sort { display: flex; align-items: center; gap: 8px; font-size: 14px; }
    .sort select { width: auto; }
    .loading { display: grid; place-items: center; min-height: 240px; }
    .more { display: flex; justify-content: center; margin-top: 28px; }
    .center { justify-content: center; margin-top: 14px; }
  `]
})
export class CatalogPage implements OnInit {
  /** Номер запроса: ответ, отставший от нового отбора, игнорируем,
   *  иначе он перезапишет свежий список устаревшим. */
  private loadSeq = 0;

  private api = inject(ApiService);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private seo = inject(SeoService);

  readonly difficulties = [
    { value: 'easy', label: 'Просто' },
    { value: 'medium', label: 'Средне' },
    { value: 'hard', label: 'Сложно' },
  ];

  q = '';
  category = '';
  maxTime = 180;
  difficulty: string[] = [];
  diet: string[] = [];
  sort: 'popular' | 'new' | 'fast' = 'popular';

  readonly recipes = signal<RecipeCard[]>([]);
  readonly categories = signal<Category[]>([]);
  readonly total = signal<number | null>(null);
  readonly page = signal(1);
  readonly lastPage = signal(1);
  readonly loading = signal(true);

  ngOnInit(): void {
    this.seo.apply({
      title: 'Все рецепты',
      description: 'Каталог рецептов с фильтрами по категории, времени, сложности и диете.',
      path: '/recipes',
    });

    this.api.categories().subscribe({
      next: (res) => this.categories.set(res.data),
      error: () => this.categories.set([]),
    });

    // Поиск из шапки приходит через ?q=
    this.route.queryParams.subscribe((params) => {
      this.q = params['q'] ?? '';
      this.category = params['category'] ?? '';
      this.load(1);
    });
  }

  private filters(page: number): CatalogFilters {
    return {
      q: this.q || undefined,
      category: this.category || undefined,
      max_time: this.maxTime < 180 ? this.maxTime : undefined,
      difficulty: this.difficulty.length ? this.difficulty : undefined,
      diet: this.diet.length ? this.diet : undefined,
      sort: this.sort,
      page,
    };
  }

  private load(page: number): void {
    this.loading.set(true);
    const seq = ++this.loadSeq;

    this.api.recipes(this.filters(page)).subscribe({
      next: (res) => {
        if (seq !== this.loadSeq) return;

        this.recipes.set(page === 1 ? res.data : [...this.recipes(), ...res.data]);
        this.total.set(res.meta.total);
        this.page.set(res.meta.current_page);
        this.lastPage.set(res.meta.last_page);
        this.loading.set(false);
      },
      error: () => {
        if (seq !== this.loadSeq) return;

        this.recipes.set([]);
        this.total.set(0);
        this.loading.set(false);
      },
    });
  }

  apply(): void {
    this.load(1);
  }

  loadMore(): void {
    this.load(this.page() + 1);
  }

  toggle(list: string[], value: string): void {
    const i = list.indexOf(value);
    if (i === -1) {
      list.push(value);
    } else {
      list.splice(i, 1);
    }
    this.apply();
  }

  toggleCategory(slug: string): void {
    this.category = this.category === slug ? '' : slug;
    this.router.navigate([], {
      relativeTo: this.route,
      queryParams: { category: this.category || null, q: this.q || null },
      queryParamsHandling: 'merge',
    });
  }

  reset(): void {
    this.q = '';
    this.category = '';
    this.maxTime = 180;
    this.difficulty = [];
    this.diet = [];
    this.sort = 'popular';
    this.router.navigate([], { relativeTo: this.route, queryParams: {} });
  }

  hasFilters(): boolean {
    return !!(this.q || this.category || this.maxTime < 180 || this.difficulty.length || this.diet.length);
  }

  categoryTitle(): string {
    return this.categories().find((c) => c.slug === this.category)?.title ?? this.category;
  }

  found(): string {
    return `Найдено ${pluralize(this.total() ?? 0, 'рецепт', 'рецепта', 'рецептов')}`;
  }
}
