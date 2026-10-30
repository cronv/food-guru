import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';

import { ApiService } from '@shared/services/api.service';
import { Category, RecipeCard } from '@shared/models/api.models';
import { pluralize } from '@shared/utils';
import { RecipeCardComponent } from '../components/recipe-card.component';
import { HttpStatusService } from '../services/http-status.service';
import { SeoService, itemList } from '../services/seo.service';

@Component({
    selector: 'app-category',
    imports: [RouterLink, RecipeCardComponent],
    template: `
    @if (category(); as c) {
      <div class="wrap band">
        <nav class="crumbs muted">
          <a routerLink="/">Главная</a> / <a routerLink="/recipes">Рецепты</a> / <span>{{ c.title }}</span>
        </nav>

        <div class="hero">
          <h1>{{ c.title }}</h1>
          @if (c.description) { <p class="lead">{{ c.description }}</p> }
          @if (total()) { <p class="count">{{ countLabel() }}</p> }
        </div>

        @if (recipes().length) {
          <div class="grid">
            @for (r of recipes(); track r.slug) { <app-recipe-card [recipe]="r" /> }
          </div>
        } @else if (!loading()) {
          <div class="empty">
            <h2>Раздел пока готовится</h2>
            <p class="muted">Рецепты этой категории ещё на модерации. Как только редакция их опубликует, они появятся здесь.</p>
            <a class="btn btn-green" routerLink="/recipes">Смотреть все рецепты</a>
          </div>
        }

        @if (others().length) {
          <div class="others">
            <h2>Другие категории</h2>
            <div class="row">
              @for (o of others(); track o.slug) {
                <a class="btn btn-ghost btn-sm" [routerLink]="['/categories', o.slug]">{{ o.title }}</a>
              }
            </div>
          </div>
        }
      </div>
    } @else if (loading()) {
      <div class="wrap band loading"><div class="spinner"></div></div>
    } @else {
      <div class="wrap band empty">
        <h1>Категория не найдена</h1>
        <a class="btn btn-green" routerLink="/recipes">К каталогу</a>
      </div>
    }
  `,
    styles: [`
    .crumbs { font-size: 13px; margin-bottom: 14px; }
    .hero { background: var(--green); color: #fff; border-radius: 20px; padding: clamp(22px, 4vw, 38px); margin-bottom: 26px; }
    .hero h1 { font-size: clamp(28px, 4vw, 42px); color: #fff; }
    .lead { margin: 12px 0 0; font-size: 17px; line-height: 1.6; max-width: 62ch; opacity: .94; }
    .count { margin: 16px 0 0; font-size: 14px; opacity: .85; }
    .others { margin-top: 40px; padding-top: 26px; border-top: 1px solid var(--line); }
    .others h2 { font-size: 22px; margin-bottom: 14px; }
    .loading { display: grid; place-items: center; min-height: 40vh; }
  `]
})
export class CategoryPage implements OnInit {
  private api = inject(ApiService);
  private route = inject(ActivatedRoute);
  private seo = inject(SeoService);
  private status = inject(HttpStatusService);

  readonly category = signal<Category | null>(null);
  readonly recipes = signal<RecipeCard[]>([]);
  readonly others = signal<Category[]>([]);
  readonly total = signal(0);
  readonly loading = signal(true);

  ngOnInit(): void {
    this.route.paramMap.subscribe((params) => {
      const slug = params.get('slug');
      if (!slug) return;
      this.load(slug);
    });

    this.api.categories().subscribe({
      next: (res) => this.setOthers(res.data),
      error: () => this.others.set([]),
    });
  }

  private load(slug: string): void {
    this.loading.set(true);
    this.api.category(slug).subscribe({
      next: (res) => {
        this.category.set(res.data);
        this.recipes.set(res.recipes.data);
        this.total.set(res.recipes.meta.total);
        this.loading.set(false);
        this.setOthers(this.others());

        this.seo.apply({
          title: res.data.title,
          description: res.data.description || `Рецепты категории «${res.data.title}».`,
          path: `/categories/${res.data.slug}`,
        });
        this.seo.breadcrumbs([
          { name: 'Главная', path: '/' },
          { name: 'Рецепты', path: '/recipes' },
          { name: res.data.title, path: `/categories/${res.data.slug}` },
        ]);
        this.seo.jsonLd('list', itemList(this.seo, res.data.title, res.recipes.data));
      },
      error: (err) => {
        this.category.set(null);
        this.loading.set(false);
        this.status.fromError(err);
      },
    });
  }

  private setOthers(all: Category[]): void {
    const current = this.category()?.slug;
    this.others.set(all.filter((c) => c.slug !== current));
  }

  countLabel(): string {
    return pluralize(this.total(), 'рецепт', 'рецепта', 'рецептов');
  }
}
