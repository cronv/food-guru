import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';

import { ApiService } from '@shared/services/api.service';
import { CollectionDetail } from '@shared/models/api.models';
import { RecipeCardComponent } from '../components/recipe-card.component';
import { HttpStatusService } from '../services/http-status.service';
import { SeoService, itemList } from '../services/seo.service';

@Component({
    selector: 'app-collection',
    imports: [RouterLink, RecipeCardComponent],
    template: `
    @if (collection(); as c) {
      <div class="wrap band">
        <nav class="crumbs muted">
          <a routerLink="/collections">Подборки</a> / <span>{{ c.title }}</span>
        </nav>

        <div class="hero">
          <h1>{{ c.title }}</h1>
          @if (c.lead) { <p class="lead">{{ c.lead }}</p> }
        </div>

        <div class="grid">
          @for (r of c.recipes; track r.slug) { <app-recipe-card [recipe]="r" /> }
          @empty { <p class="muted">В подборке пока нет опубликованных рецептов.</p> }
        </div>
      </div>
    } @else if (loading()) {
      <div class="wrap band loading"><div class="spinner"></div></div>
    } @else {
      <div class="wrap band empty">
        <h1>Подборка не найдена</h1>
        <a class="btn btn-green" routerLink="/collections">Все подборки</a>
      </div>
    }
  `,
    styles: [`
    .crumbs { font-size: 13px; margin-bottom: 14px; }
    .hero { background: var(--brick); color: #fff; border-radius: 20px; padding: clamp(22px, 4vw, 38px); margin-bottom: 28px; }
    .hero h1 { font-size: clamp(28px, 4vw, 40px); color: #fff; }
    .lead { margin: 12px 0 0; font-size: 17px; line-height: 1.6; max-width: 62ch; opacity: .92; }
    .loading { display: grid; place-items: center; min-height: 40vh; }
  `]
})
export class CollectionPage implements OnInit {
  private api = inject(ApiService);
  private route = inject(ActivatedRoute);
  private seo = inject(SeoService);
  private status = inject(HttpStatusService);

  readonly collection = signal<CollectionDetail | null>(null);
  readonly loading = signal(true);

  ngOnInit(): void {
    this.route.paramMap.subscribe((params) => {
      const slug = params.get('slug');
      if (!slug) return;

      this.loading.set(true);
      this.api.collection(slug).subscribe({
        next: (res) => {
          this.collection.set(res.data);
          this.loading.set(false);
          this.seo.apply({
            title: res.data.title,
            description: res.data.lead,
            path: `/collections/${res.data.slug}`,
          });
          this.seo.breadcrumbs([
            { name: 'Главная', path: '/' },
            { name: 'Подборки', path: '/collections' },
            { name: res.data.title, path: `/collections/${res.data.slug}` },
          ]);
          this.seo.jsonLd('list', itemList(this.seo, res.data.title, res.data.recipes ?? []));
        },
        error: (err) => {
          this.collection.set(null);
          this.loading.set(false);
          this.status.fromError(err);
        },
      });
    });
  }
}
