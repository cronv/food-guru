import { Component, HostListener, Input, signal } from '@angular/core';

import { Media } from '@shared/models/api.models';

@Component({
  selector: 'app-gallery',
  standalone: true,
  template: `
    @if (items.length) {
      <div class="main">
        <img [src]="current().url" [alt]="current().alt || title" />

        @if (items.length > 1) {
          <button class="nav left" type="button" (click)="move(-1)" aria-label="Предыдущий кадр">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="m14.5 5-7 7 7 7" /></svg>
          </button>
          <button class="nav right" type="button" (click)="move(1)" aria-label="Следующий кадр">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="m9.5 5 7 7-7 7" /></svg>
          </button>
          <button class="count" type="button" (click)="box.set(true)" aria-label="Открыть во весь экран">
            {{ index() + 1 }} / {{ items.length }}
          </button>
        }
      </div>

      @if (items.length > 1) {
        <div class="thumbs" role="tablist" aria-label="Кадры рецепта">
          @for (m of items; track m.id; let i = $index) {
            <button role="tab" type="button" [attr.aria-current]="i === index()"
                    (click)="index.set(i)" [attr.aria-label]="m.caption || m.alt || 'Кадр ' + (i + 1)">
              <img [src]="m.url" [alt]="m.alt || ''" loading="lazy" />
            </button>
          }
        </div>
      }

      @if (current().caption) {
        <p class="muted cap">{{ current().caption }}</p>
      }

      @if (box()) {
        <!-- Закрывается кликом мимо снимка, кнопкой и клавишей Escape. -->
        <div class="lightbox" role="dialog" aria-modal="true" aria-label="Просмотр снимка" tabindex="-1"
             (click)="$event.target === $event.currentTarget && box.set(false)" (keydown.escape)="box.set(false)">
          <img [src]="current().url" [alt]="current().alt || title" />
          <p class="cap-light">{{ current().caption || title }} · {{ index() + 1 }} из {{ items.length }}</p>
          <button class="btn btn-ghost btn-sm close" type="button" (click)="box.set(false)">Закрыть</button>
        </div>
      }
    }
  `,
  styles: [`
    .main {
      position: relative; border-radius: 18px; overflow: hidden;
      background: var(--sunken); aspect-ratio: 16/10; max-height: 440px;
    }
    .main img { width: 100%; height: 100%; object-fit: cover; }
    .nav {
      position: absolute; top: 50%; transform: translateY(-50%);
      width: 44px; height: 44px; border-radius: 999px; border: 0;
      background: rgba(22, 21, 15, .55); color: #fff; display: grid; place-items: center;
    }
    .nav:hover { background: rgba(22, 21, 15, .78); }
    .left { left: 12px; }
    .right { right: 12px; }
    .count {
      position: absolute; right: 12px; bottom: 12px; padding: 5px 12px; border: 0;
      border-radius: 999px; background: rgba(22, 21, 15, .6); color: #fff;
      font-size: 12px; font-weight: 700;
    }
    .thumbs { display: flex; gap: 8px; overflow-x: auto; padding-bottom: 4px; margin-top: 10px; }
    .thumbs button {
      flex: 0 0 84px; height: 64px; border-radius: 10px; overflow: hidden;
      border: 2px solid transparent; background: var(--sunken); padding: 0;
    }
    .thumbs button[aria-current="true"] { border-color: var(--green); }
    .thumbs img { width: 100%; height: 100%; object-fit: cover; }
    .cap { margin: 8px 0 0; font-size: 13px; }
    .lightbox {
      position: fixed; inset: 0; z-index: 50; background: rgba(12, 11, 8, .94);
      display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 14px;
      padding: max(20px, env(safe-area-inset-top, 0px)) 20px max(20px, env(safe-area-inset-bottom, 0px));
    }
    .lightbox img { max-width: min(96vw, 1000px); max-height: 76vh; border-radius: 14px; }
    .cap-light { color: #EDE7DA; font-size: 14px; text-align: center; margin: 0; }
    .close { background: rgba(255, 255, 255, .1); color: #fff; border-color: rgba(255, 255, 255, .3); }
  `],
})
export class GalleryComponent {
  @Input() items: Media[] = [];
  @Input() title = '';

  readonly index = signal(0);
  readonly box = signal(false);

  current(): Media {
    return this.items[Math.min(this.index(), this.items.length - 1)];
  }

  move(step: number): void {
    const n = this.items.length;
    if (!n) return;
    this.index.set((this.index() + step + n) % n);
  }

  @HostListener('document:keydown', ['$event'])
  onKey(event: KeyboardEvent): void {
    if (!this.items.length) return;
    const target = event.target as HTMLElement | null;
    if (target && /INPUT|TEXTAREA|SELECT/.test(target.tagName)) return;

    if (event.key === 'Escape') this.box.set(false);
    if (event.key === 'ArrowLeft') this.move(-1);
    if (event.key === 'ArrowRight') this.move(1);
  }
}
