import { Component, Input } from '@angular/core';
import { RouterLink } from '@angular/router';

import { RecipeCard, DIFFICULTY_LABELS } from '@shared/models/api.models';
import { pluralize } from '@shared/utils';

@Component({
    selector: 'app-recipe-card',
    imports: [RouterLink],
    template: `
    <a class="card" [routerLink]="['/recipes', recipe.slug]">
      <div class="shot">
        @if (recipe.cover) {
          <img [src]="recipe.cover.url" [alt]="recipe.cover.alt || recipe.title" loading="lazy">
        } @else {
          <div class="placeholder" aria-hidden="true">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3">
              <path d="M3 13h18a9 9 0 0 1-18 0Z" /><path d="M12 3.5v3.2" /><path d="M8.4 6.7h7.2" />
            </svg>
          </div>
        }
      </div>

      <div class="row tags">
        @if (recipe.category) { <span class="chip chip-g">{{ recipe.category.title }}</span> }
        @if (recipe.time_minutes) { <span class="chip">{{ recipe.time_minutes }} мин</span> }
      </div>

      <h3>{{ recipe.title }}</h3>

      <p class="muted meta">
        @if (recipe.time_minutes) { {{ recipe.time_minutes }} мин · }
        {{ difficulty }}
        @if (recipe.calories) { · <span class="tnum">{{ recipe.calories }}</span> ккал }
        @if (recipe.rating) { · {{ recipe.rating }} ★ }
      </p>
      @if (recipe.saves_count) {
        <p class="muted saves tnum" [attr.aria-label]="'Сохранили в рецептосохранялку: ' + recipe.saves_count">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M6 3h12a1 1 0 0 1 1 1v17l-7-4-7 4V4a1 1 0 0 1 1-1z"/></svg>
          {{ savesText }}
        </p>
      }
    </a>
  `,
    styles: [`
    .card { height: 100%; }
    .shot {
      height: 160px;
      border-radius: 12px;
      overflow: hidden;
      background: var(--sunken);
      display: grid;
      place-items: center;
    }
    .shot img { width: 100%; height: 100%; object-fit: cover; }
    .placeholder { color: var(--muted); }
    .tags { padding: 0 4px; }
    h3 { font-family: var(--body); font-size: 17px; font-weight: 600; letter-spacing: 0; padding: 0 4px; }
    .meta { margin: 0 0 6px; padding: 0 4px; font-size: 13px; }
    .saves { margin: -2px 0 6px; padding: 0 4px; font-size: 12px; display: flex; align-items: center; gap: 5px; }
  `]
})
export class RecipeCardComponent {
  @Input({ required: true }) recipe!: RecipeCard;

  get savesText(): string {
    const n = this.recipe.saves_count ?? 0;
    return `сохранили ${pluralize(n, 'человек', 'человека', 'человек')}`;
  }

  get difficulty(): string {
    return DIFFICULTY_LABELS[this.recipe.difficulty] ?? '';
  }
}
