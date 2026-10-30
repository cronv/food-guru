import { Component, computed, input, output, signal } from '@angular/core';

/** Цвета для образцов в фильтре. Нет в словаре — показываем только подпись. */
const SWATCH: Record<string, string> = {
  'Белый': '#FFFFFF', 'Чёрный': '#1E1E22', 'Красный': '#D33A2C', 'Стальной': '#B8BEC6',
  'Натуральный': '#C8955A', 'Зелёный': '#2E8B57', 'Синий': '#2A6FD6', 'Серый': '#8A8F96',
  'Бежевый': '#E8D9BF', 'Жёлтый': '#E8B92E', 'Коричневый': '#7A4B2A', 'Медный': '#B87333',
};

/**
 * Группа галочек фильтра. Длинный список сворачивается до пяти пунктов —
 * чтобы у авторов и стран панель не уезжала на экран вниз.
 */
@Component({
  selector: 'app-check-group',
  standalone: true,
  template: `
    @if (options().length) {
      <fieldset class="group">
        <legend>{{ title() }}</legend>
        @for (o of visible(); track o) {
          <label class="opt">
            <input type="checkbox" [checked]="selected().includes(o)" (change)="toggle(o)">
            @if (swatches() && swatch(o); as c) { <span class="sw" [style.background]="c" aria-hidden="true"></span> }
            <span>{{ o }}</span>
          </label>
        }
        @if (options().length > 5) {
          <button type="button" class="more" (click)="all.set(!all())">
            {{ all() ? 'Свернуть' : 'Ещё ' + (options().length - 5) }}
          </button>
        }
      </fieldset>
    }
  `,
  styles: [`
    .group { border: 0; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 2px; }
    legend { font-weight: 700; margin-bottom: 6px; font-size: 14px; }
    .opt { display: flex; align-items: center; gap: 10px; min-height: 34px; cursor: pointer; font-size: 14px; }
    .sw { width: 16px; height: 16px; border-radius: 999px; border: 1px solid rgb(0 0 0 / .18); flex: 0 0 auto; }
    .more { align-self: flex-start; border: 0; background: none; padding: 4px 0; color: var(--green-ink); font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; }
  `],
})
export class CheckGroupComponent {
  readonly title = input.required<string>();
  readonly options = input<string[]>([]);
  readonly selected = input<string[]>([]);
  readonly swatches = input(false);
  readonly changed = output<string[]>();

  readonly all = signal(false);
  readonly visible = computed(() => {
    const opts = this.options();
    if (this.all() || opts.length <= 5) return opts;
    // Выбранное не прячем, даже если оно дальше пятого места.
    const head = opts.slice(0, 5);
    return [...head, ...opts.slice(5).filter((o) => this.selected().includes(o))];
  });

  swatch(name: string): string | null {
    return SWATCH[name] ?? null;
  }

  toggle(o: string): void {
    const cur = this.selected();
    this.changed.emit(cur.includes(o) ? cur.filter((x) => x !== o) : [...cur, o]);
  }
}

/** Диапазон «от — до». Пустое поле — граница не задана. Изменение — по уходу с поля или Enter. */
@Component({
  selector: 'app-range',
  standalone: true,
  template: `
    <fieldset class="group">
      <legend>{{ title() }}</legend>
      <div class="pair">
        <label><span class="sr">{{ title() }} от</span>
          <input type="number" inputmode="numeric" [placeholder]="'от ' + (minHint() ?? '')" [value]="from() ?? ''"
                 (change)="emit($any($event.target).value, to())">
        </label>
        <span aria-hidden="true">—</span>
        <label><span class="sr">{{ title() }} до</span>
          <input type="number" inputmode="numeric" [placeholder]="'до ' + (maxHint() ?? '')" [value]="to() ?? ''"
                 (change)="emit(from(), $any($event.target).value)">
        </label>
      </div>
    </fieldset>
  `,
  styles: [`
    .group { border: 0; margin: 0; padding: 0; }
    legend { font-weight: 700; margin-bottom: 6px; font-size: 14px; }
    .pair { display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; gap: 6px; }
    .pair input { min-height: 40px; padding: 0 10px; font-size: 14px; }
    .sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
  `],
})
export class RangeComponent {
  readonly title = input.required<string>();
  readonly from = input<number | null>(null);
  readonly to = input<number | null>(null);
  readonly minHint = input<number | null>(null);
  readonly maxHint = input<number | null>(null);
  readonly changed = output<{ from: number | null; to: number | null }>();

  emit(from: unknown, to: unknown): void {
    const n = (v: unknown) => (v === '' || v === null || v === undefined || Number.isNaN(Number(v)) ? null : Math.max(0, Number(v)));
    let a = n(from), b = n(to);
    // Перепутали границы — меняем местами, а не показываем пустой список.
    if (a !== null && b !== null && a > b) [a, b] = [b, a];
    this.changed.emit({ from: a, to: b });
  }
}
