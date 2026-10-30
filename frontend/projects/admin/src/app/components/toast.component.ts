import { Component, inject } from '@angular/core';

import { ToastService } from '../services/toast.service';

@Component({
  selector: 'adm-toast',
  standalone: true,
  template: `
    <div class="toasts" role="status" aria-live="polite">
      @for (m of toasts.messages(); track m.id) {
        <div class="toast" [class.toast-error]="m.kind === 'error'">{{ m.text }}</div>
      }
    </div>
  `,
  styles: [`
    .toasts {
      position: fixed;
      left: 50%;
      transform: translateX(-50%);
      bottom: calc(20px + env(safe-area-inset-bottom, 0px));
      z-index: 60;
      display: flex;
      flex-direction: column;
      gap: 8px;
      align-items: center;
      pointer-events: none;
    }
    .toast {
      background: var(--panel);
      color: #fff;
      padding: 12px 20px;
      border-radius: 999px;
      font-size: 14px;
      font-weight: 600;
      box-shadow: 0 6px 24px rgba(0, 0, 0, .25);
      max-width: min(92vw, 440px);
      text-align: center;
    }
    .toast-error { background: var(--brick); color: var(--on-accent); }
  `],
})
export class ToastComponent {
  toasts = inject(ToastService);
}
