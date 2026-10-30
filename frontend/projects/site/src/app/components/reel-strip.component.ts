import { Component, input } from '@angular/core';
import { RouterLink } from '@angular/router';

import { RecipeCard } from '@shared/models/api.models';

/** Горизонтальная лента роликов для главной: постер, значок «играть» и название. */
@Component({
    selector: 'app-reel-strip',
    imports: [RouterLink],
    template: `
    <div class="strip" role="list">
      @for (r of recipes(); track r.slug) {
        <a class="item" role="listitem" [routerLink]="['/recipes', r.slug]"
           [attr.aria-label]="'Видеорецепт: ' + r.title">
          <span class="poster">
            @if (r.cover) {
              <img [src]="r.cover.url" [alt]="''" loading="lazy">
            }
            <span class="play" aria-hidden="true">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.5v13l11-6.5z"/></svg>
            </span>
          </span>
          <span class="name">{{ r.title }}</span>
          @if (r.time_minutes) { <span class="muted time">{{ r.time_minutes }} мин</span> }
        </a>
      }
    </div>
  `,
    styles: [`
    .strip {
      display: flex; gap: 14px; overflow-x: auto; padding-bottom: 8px;
      scroll-snap-type: x mandatory; -webkit-overflow-scrolling: touch;
    }
    .item {
      flex: 0 0 158px; scroll-snap-align: start; display: flex; flex-direction: column;
      gap: 6px; text-decoration: none; color: inherit;
    }
    .poster {
      position: relative; aspect-ratio: 9 / 16; border-radius: 16px; overflow: hidden;
      background: var(--sunken); display: block;
    }
    .poster img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .play {
      position: absolute; inset: 0; margin: auto; width: 48px; height: 48px; border-radius: 999px;
      background: rgb(0 0 0 / .55); color: #fff; display: grid; place-items: center;
    }
    .name { font-weight: 600; font-size: 14px; line-height: 1.25; }
    .time { font-size: 12px; }
  `]
})
export class ReelStripComponent {
  readonly recipes = input.required<RecipeCard[]>();
}
