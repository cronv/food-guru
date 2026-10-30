import { Component, input } from '@angular/core';
import { RouterLink } from '@angular/router';

export interface Crumb {
  label: string;
  /** Нет ссылки — это текущая страница. */
  link?: string | unknown[];
}

/**
 * Хлебные крошки. На узком экране длинная цепочка не переносится в три строки,
 * а прокручивается вбок, и сразу видна последняя — текущая — страница.
 */
@Component({
    selector: 'app-breadcrumbs',
    imports: [RouterLink],
    template: `
    <nav class="crumbs" aria-label="Навигация по разделам">
      <ol>
        @for (c of items(); track $index; let last = $last) {
          <li>
            @if (c.link && !last) {
              <a [routerLink]="c.link">{{ c.label }}</a>
            } @else {
              <span [attr.aria-current]="last ? 'page' : null">{{ c.label }}</span>
            }
          </li>
        }
      </ol>
    </nav>
  `,
    styles: [`
    .crumbs { margin-bottom: 14px; font-size: 13px; color: var(--muted); }
    ol {
      list-style: none; margin: 0; padding: 0; display: flex; align-items: center; gap: 6px;
      overflow-x: auto; white-space: nowrap; scrollbar-width: none;
    }
    ol::-webkit-scrollbar { display: none; }
    li { display: flex; align-items: center; gap: 6px; flex: 0 0 auto; }
    li + li::before { content: '/'; opacity: .5; }
    a { color: var(--muted); padding: 6px 0; }
    a:hover { color: var(--green-ink); }
    [aria-current='page'] { color: var(--ink); font-weight: 600; }
  `]
})
export class BreadcrumbsComponent {
  readonly items = input.required<Crumb[]>();
}
