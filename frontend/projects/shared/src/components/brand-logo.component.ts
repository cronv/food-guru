import { ChangeDetectionStrategy, Component, Input } from '@angular/core';

/**
 * Логотип Food Guru.
 *
 * SVG вставлен прямо в шаблон, а не подключён как <img>, по одной причине:
 * надпись должна следовать цвету темы, а <img> не наследует color. Поэтому
 * буквы нарисованы currentColor, а кольцо и лист — фирменными цветами:
 * оба проходят по контрасту и на светлом, и на тёмном фоне, так что
 * отдельная версия под тёмную тему не нужна.
 *
 * Высота задаётся снаружи, через CSS на самом элементе. Пропорции трогать
 * нельзя: ширина считается из viewBox.
 */
@Component({
  selector: 'fg-logo',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (variant === 'mark') {
      <svg [attr.viewBox]="'0 0 120 142'" role="img" [attr.aria-label]="label">
        <circle cx="60" cy="82" r="43" fill="none" stroke="#E3401F" stroke-width="34"/>
        <path d="M84 24C92 10 104 2 116 0C112 12 102 21 92 27Z" fill="#1E8A57"/>
      </svg>
    } @else {
      <svg [attr.viewBox]="'0 -2 1040 142'" role="img" [attr.aria-label]="label">
        <g fill="none" stroke="currentColor" stroke-width="34"
           stroke-linecap="round" stroke-linejoin="round">
          <path d="M17 37V123M17 37H59M17 90H50"/>
          <circle cx="152" cy="80" r="43"/>
          <path d="M381 37V123M381 37H416A43 43 0 0 1 416 123H381"/>
          <path d="M611.6 47.1A43 43 0 1 0 623.5 97M623.5 97H590"/>
          <path d="M677 37V80A43 43 0 0 0 763 80V37"/>
          <path d="M813 37V123M813 37H844A30 30 0 0 1 844 97H813M817 95L887 123"/>
          <path d="M937 37V80A43 43 0 0 0 1023 80V37"/>
        </g>
        <circle cx="288" cy="80" r="43" fill="none" stroke="#E3401F" stroke-width="34"/>
        <path d="M312 22C320 8 332 0 344 -2C340 10 330 19 320 25Z" fill="#1E8A57"/>
      </svg>
    }
  `,
  styles: [`
    :host { display: inline-block; height: 26px; line-height: 0; }
    svg { height: 100%; width: auto; display: block; }
  `],
})
export class BrandLogoComponent {
  /** Полная надпись или только знак — для узких мест. */
  @Input() variant: 'wordmark' | 'mark' = 'wordmark';

  /**
   * Подпись для скринридера. Пустая строка — если рядом уже есть
   * название текстом и логотип ничего не добавляет.
   */
  @Input() label = 'Food Guru';
}
