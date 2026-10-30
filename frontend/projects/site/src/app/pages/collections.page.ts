import { Component, OnInit, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';

import { ApiService } from '@shared/services/api.service';
import { CollectionCard } from '@shared/models/api.models';
import { pluralize } from '@shared/utils';
import { SeoService } from '../services/seo.service';

@Component({
    selector: 'app-collections',
    imports: [RouterLink],
    template: `
    <div class="wrap band">
      <h1>Подборки редакции</h1>
      <p class="muted sub">Рецепты, собранные вокруг одной задачи: сезон, посуда, повод.</p>

      @if (items().length) {
        <div class="grid cols">
          @for (c of items(); track c.slug; let i = $index) {
            <a class="tile" [class]="'tile-' + (i % 3)" [routerLink]="['/collections', c.slug]">
              <span class="count">{{ count(c.recipes_count) }}</span>
              <span class="name">{{ c.title }}</span>
              @if (c.lead) { <span class="lead">{{ c.lead }}</span> }
            </a>
          }
        </div>
      } @else if (!loading()) {
        <div class="empty">
          <h2>Подборок пока нет</h2>
          <p class="muted">Редакция их готовит. Пока можно посмотреть каталог целиком.</p>
          <a class="btn btn-green" routerLink="/recipes">К каталогу</a>
        </div>
      } @else {
        <div class="loading"><div class="spinner"></div></div>
      }
    </div>
  `,
    styles: [`
    h1 { font-size: clamp(28px, 4vw, 40px); }
    .sub { margin: 6px 0 26px; }
    .cols { grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }
    .tile {
      display: flex; flex-direction: column; justify-content: flex-end;
      min-height: 240px; padding: 24px; border-radius: 18px; color: #fff; gap: 10px;
    }
    .tile:hover { color: #fff; opacity: .94; }
    .tile-0 { background: var(--green); }
    .tile-1 { background: var(--brick); }
    .tile-2 { background: var(--panel); }
    .count { font-size: 11px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; opacity: .85; }
    .name { font-family: var(--display); font-size: 25px; font-weight: 600; line-height: 1.2; }
    .lead { font-size: 14px; opacity: .9; line-height: 1.5; }
    .loading { display: grid; place-items: center; min-height: 30vh; }
  `]
})
export class CollectionsPage implements OnInit {
  private api = inject(ApiService);
  private seo = inject(SeoService);

  readonly items = signal<CollectionCard[]>([]);
  readonly loading = signal(true);

  ngOnInit(): void {
    this.seo.apply({
      title: 'Подборки редакции',
      description: 'Рецепты, собранные вокруг одной задачи: сезон, посуда, повод.',
      path: '/collections',
    });

    this.api.collections().subscribe({
      next: (res) => {
        this.items.set(res.data);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  count(n: number): string {
    return pluralize(n, 'рецепт', 'рецепта', 'рецептов');
  }
}
