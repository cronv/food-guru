import { Component, computed, input, signal } from '@angular/core';

import { Step } from '@shared/models/api.models';

type TabKey = 'tip' | 'substitution' | 'chef_secret';

const TABS: { key: TabKey; label: string; empty: string }[] = [
  { key: 'tip', label: 'Совет', empty: 'Совета к этому шагу пока нет.' },
  { key: 'substitution', label: 'Замена ингредиентов', empty: 'Замен для этого шага редакция пока не предлагает.' },
  { key: 'chef_secret', label: 'Секреты шеф-повара', empty: 'Секретов к этому шагу пока нет.' },
];

/**
 * Три вкладки под шагом. Изначально свёрнуты: текст шага читается подряд,
 * а подробности открываются по нажатию. Повторное нажатие закрывает вкладку.
 */
@Component({
  selector: 'app-step-tabs',
  standalone: true,
  template: `
    <div class="tabs" role="group" [attr.aria-label]="'Подробности шага ' + number()">
      @for (t of tabs; track t.key) {
        <button type="button" [class.on]="open() === t.key" [class.has]="!!text(t.key)"
                [attr.aria-expanded]="open() === t.key" [attr.aria-controls]="panelId()"
                (click)="toggle(t.key)">
          {{ t.label }}
        </button>
      }
    </div>

    @if (current(); as t) {
      <div class="panel" [id]="panelId()" role="region" [attr.aria-label]="t.label">
        @if (text(t.key); as body) {
          <p>{{ body }}</p>
        } @else {
          <p class="muted">{{ t.empty }}</p>
        }
      </div>
    }
  `,
  styles: [`
    :host { display: block; margin-top: 10px; }
    .tabs { display: flex; flex-wrap: wrap; gap: 6px; }
    button {
      min-height: 36px; padding: 0 12px; border-radius: 999px; font: inherit; font-size: 13px; font-weight: 600;
      border: 1px solid var(--line); background: var(--surface); color: var(--muted); cursor: pointer;
    }
    /* Вкладка с текстом заметнее пустой — видно, где есть что почитать. */
    button.has { color: var(--ink); }
    button.on { background: var(--green); border-color: var(--green); color: var(--on-accent); }
    .panel {
      margin-top: 8px; padding: 12px 14px; border-radius: 12px;
      background: var(--green-soft); color: var(--green-ink); font-size: 15px; line-height: 1.55;
    }
    .panel p { margin: 0; }
    .panel .muted { color: var(--muted); }
  `],
})
export class StepTabsComponent {
  readonly step = input.required<Step>();
  readonly number = input(1);

  readonly tabs = TABS;
  readonly open = signal<TabKey | null>(null);
  readonly current = computed(() => TABS.find((t) => t.key === this.open()) ?? null);
  readonly panelId = computed(() => `step-${this.step().id}-panel`);

  text(key: TabKey): string | null {
    return this.step()[key]?.trim() || null;
  }

  toggle(key: TabKey): void {
    this.open.set(this.open() === key ? null : key);
  }
}
