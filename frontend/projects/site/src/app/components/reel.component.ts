import { Component, input } from '@angular/core';

/**
 * Вертикальный видеорецепт. Файл не грузится, пока человек не нажал «играть»
 * (preload="none"): на странице с несколькими роликами иначе каждый тянул бы
 * мегабайты ещё до просмотра. До нажатия виден постер — обложка рецепта.
 */
@Component({
  selector: 'app-reel',
  standalone: true,
  template: `
    <div class="frame">
      <video [src]="src()" [attr.poster]="poster() || null" controls playsinline preload="none"
             [attr.aria-label]="'Видеорецепт: ' + title()"></video>
    </div>
  `,
  styles: [`
    :host { display: block; }
    .frame {
      aspect-ratio: 9 / 16; width: 100%; max-width: 340px;
      border-radius: 18px; overflow: hidden; background: #17120e;
    }
    video { width: 100%; height: 100%; object-fit: cover; display: block; }
  `],
})
export class ReelComponent {
  readonly src = input.required<string>();
  readonly title = input('');
  readonly poster = input<string | null | undefined>(null);
}
