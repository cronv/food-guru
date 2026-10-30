import { Component, Input } from '@angular/core';

@Component({
  selector: 'app-stars',
  standalone: true,
  template: `
    <span class="stars" role="img" [attr.aria-label]="'Оценка ' + rounded + ' из 5'">
      @for (n of [1, 2, 3, 4, 5]; track n) {
        <svg [attr.width]="size" [attr.height]="size" viewBox="0 0 24 24"
             [attr.fill]="n <= rounded ? 'var(--brick)' : 'none'"
             [attr.stroke]="n <= rounded ? 'var(--brick)' : 'var(--line)'" stroke-width="1.6">
          <path d="m12 3.6 2.6 5.4 5.9.8-4.3 4.1 1.1 5.9L12 17l-5.3 2.8 1.1-5.9-4.3-4.1 5.9-.8z" />
        </svg>
      }
    </span>
  `,
  styles: [`.stars { display: inline-flex; gap: 2px; vertical-align: middle; }`],
})
export class StarsComponent {
  @Input() value = 0;
  @Input() size = 16;

  get rounded(): number {
    return Math.round(this.value);
  }
}
