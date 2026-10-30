import { Component, OnInit, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';

import { ApiService } from '@shared/services/api.service';
import { RecipeCard } from '@shared/models/api.models';
import { errorText } from '@shared/utils';
import { ReelComponent } from '../components/reel.component';
import { SeoService } from '../services/seo.service';

@Component({
    selector: 'app-reels',
    imports: [RouterLink, ReelComponent],
    template: `
    <div class="wrap band">
      <h1>Рилсы</h1>
      <p class="muted lead">
        Короткие вертикальные видео: граммовки, температура и время прямо на экране.
      </p>

      @if (items().length) {
        <div class="grid">
          @for (r of items(); track r.slug) {
            <article class="one">
              <app-reel [src]="r.reel_url!" [title]="r.title" [poster]="r.cover?.url" />
              <h2><a [routerLink]="['/recipes', r.slug]">{{ r.title }}</a></h2>
              <p class="muted meta">
                @if (r.time_minutes) { {{ r.time_minutes }} мин · }
                @if (r.category) { {{ r.category.title }} }
              </p>
              <a class="btn btn-ghost btn-sm" [routerLink]="['/recipes', r.slug]">Открыть рецепт</a>
            </article>
          }
        </div>

        @if (hasMore()) {
          <div class="more">
            <button class="btn btn-line" type="button" [disabled]="loading()" (click)="load()">
              {{ loading() ? 'Загружаем…' : 'Показать ещё' }}
            </button>
          </div>
        }
      } @else if (loading()) {
        <div class="state"><div class="spinner"></div></div>
      } @else if (error()) {
        <div class="state empty">
          <h2>Не удалось загрузить ролики</h2>
          <p class="muted">{{ error() }}</p>
        </div>
      } @else {
        <div class="state empty">
          <h2>Роликов пока нет</h2>
          <p class="muted">Они появятся, когда редакция загрузит первые.</p>
        </div>
      }
    </div>
  `,
    styles: [`
    h1 { font-size: clamp(28px, 4vw, 38px); }
    .lead { margin: 8px 0 24px; max-width: 60ch; }
    .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 28px 20px; }
    .one { display: flex; flex-direction: column; gap: 8px; min-width: 0; }
    .one h2 { font-family: var(--body); font-size: 17px; font-weight: 600; letter-spacing: 0; margin: 6px 0 0; }
    .one h2 a { text-decoration: none; color: inherit; }
    .meta { margin: 0; font-size: 13px; }
    .one .btn { align-self: flex-start; }
    .more { display: grid; place-items: center; margin-top: 28px; }
    .state { display: grid; place-items: center; min-height: 200px; text-align: center; }
  `]
})
export class ReelsPage implements OnInit {
  private api = inject(ApiService);
  private seo = inject(SeoService);

  readonly items = signal<RecipeCard[]>([]);
  readonly loading = signal(true);
  readonly hasMore = signal(false);
  readonly error = signal('');

  private page = 0;

  ngOnInit(): void {
    this.seo.apply({
      title: 'Рилсы — видеорецепты',
      description: 'Короткие вертикальные видео с граммовками, температурой и временем на экране.',
      path: '/reels',
    });
    this.load();
  }

  load(): void {
    if (this.loading() && this.page > 0) return;

    this.loading.set(true);
    this.api.reels(this.page + 1).subscribe({
      next: (res) => {
        this.page = res.meta.current_page;
        this.items.update((list) => [...list, ...res.data.filter((r) => r.reel_url)]);
        this.hasMore.set(res.meta.current_page < res.meta.last_page);
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(errorText(err));
        this.loading.set(false);
      },
    });
  }
}
