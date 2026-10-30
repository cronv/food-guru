import { Component, computed, input, signal } from '@angular/core';

import { pluralize } from '@shared/utils';

/** Энергия на грамм: белки и углеводы — 4 ккал, жиры — 9. */
const KCAL_PER_GRAM = { protein: 4, carbs: 4, fat: 9 } as const;

type Macro = keyof typeof KCAL_PER_GRAM;

export interface MacroSlice {
  key: Macro;
  label: string;
  grams: number;
  kcal: number;
  /** Доля в энергии из БЖУ, 0–100, сумма по всем — ровно 100. */
  share: number;
}

const LABELS: Record<Macro, string> = { protein: 'Белки', carbs: 'Углеводы', fat: 'Жиры' };

/**
 * Порядок фиксирован: белки, углеводы, жиры. Зелёный и янтарный при протанопии
 * близки, поэтому между ними стоит синий — так соседние сегменты различимы всегда.
 */
const ORDER: Macro[] = ['protein', 'carbs', 'fat'];

/** Доли в целых процентах, сумма ровно 100 (метод наибольшего остатка). */
export function macroSlices(protein: number, carbs: number, fat: number): MacroSlice[] {
  const grams: Record<Macro, number> = { protein, carbs, fat };
  const kcal = ORDER.map((k) => Math.max(0, grams[k] || 0) * KCAL_PER_GRAM[k]);
  const total = kcal.reduce((a, b) => a + b, 0);
  if (!total) return [];

  const raw = kcal.map((k) => (k / total) * 100);
  const floored = raw.map(Math.floor);
  let left = 100 - floored.reduce((a, b) => a + b, 0);
  raw.map((r, i) => [r - floored[i], i] as const)
    .sort((a, b) => b[0] - a[0])
    .forEach(([, i]) => { if (left > 0) { floored[i]++; left--; } });

  return ORDER.map((key, i) => ({
    key, label: LABELS[key], grams: Math.max(0, grams[key] || 0), kcal: Math.round(kcal[i]), share: floored[i],
  }));
}

/**
 * Калорийность состава: откуда берётся энергия порции. Это доли одного целого,
 * поэтому одна полоса со 100% накоплением, а не круговая диаграмма — длины
 * на одной линии сравниваются точнее углов.
 */
@Component({
  selector: 'app-nutrition-chart',
  standalone: true,
  template: `
    @if (slices().length) {
      <figure class="viz" [attr.aria-label]="summary()">
        <div class="head">
          <div>
            <span class="k">На порцию</span>
            <span class="big tnum">{{ perPortion() }} ккал</span>
          </div>
          @if (servings() !== 1) {
            <div>
              <span class="k">На {{ servingsText() }}</span>
              <span class="big tnum">{{ total() }} ккал</span>
            </div>
          }
        </div>

        <div class="labels" aria-hidden="true">
          @for (s of slices(); track s.key) {
            <span [style.flex-grow]="s.share">{{ s.share >= 10 ? s.share + '%' : '' }}</span>
          }
        </div>

        <div class="bar" role="img" [attr.aria-label]="summary()">
          @for (s of slices(); track s.key) {
            <span class="seg" [class]="'seg seg-' + s.key" [style.flex-grow]="s.share"
                  tabindex="0" [attr.aria-label]="describe(s)"
                  (mouseenter)="active.set(s.key)" (mouseleave)="active.set(null)"
                  (focus)="active.set(s.key)" (blur)="active.set(null)"></span>
          }
        </div>

        <p class="tip" aria-live="polite">
          @if (activeSlice(); as s) { {{ describe(s) }} } @else { &nbsp; }
        </p>

        <!-- Легенда — она же таблица значений: цвет никогда не единственный носитель смысла. -->
        <table class="legend">
          <caption class="sr">Состав одной порции</caption>
          <thead class="sr"><tr><th>Нутриент</th><th>Граммы</th><th>Ккал</th><th>Доля</th></tr></thead>
          <tbody>
            @for (s of slices(); track s.key) {
              <tr [class.on]="active() === s.key">
                <th scope="row"><span class="sw" [class]="'sw sw-' + s.key" aria-hidden="true"></span>{{ s.label }}</th>
                <td class="tnum">{{ s.grams }} г</td>
                <td class="tnum">{{ s.kcal }} ккал</td>
                <td class="tnum">{{ s.share }}%</td>
              </tr>
            }
          </tbody>
        </table>

        <figcaption class="muted">
          Доли — по энергии из белков, жиров и углеводов: 4, 9 и 4 ккал на грамм.
        </figcaption>
      </figure>
    }
  `,
  styles: [`
    :host {
      display: block;
      --c-protein: #17704A; --c-carbs: #2a78d6; --c-fat: #C98500;
    }
    @media (prefers-color-scheme: dark) {
      :host-context(html:not([data-theme="light"])) { --c-protein: #2E9E66; --c-carbs: #3987e5; --c-fat: #C98500; }
    }
    :host-context(html[data-theme="dark"]) { --c-protein: #2E9E66; --c-carbs: #3987e5; --c-fat: #C98500; }

    .viz {
      margin: 0; background: var(--surface); border: 1px solid var(--line);
      border-radius: 18px; padding: 18px; display: flex; flex-direction: column; gap: 10px;
    }
    .head { display: flex; flex-wrap: wrap; gap: 24px; }
    .k { display: block; font-size: 11px; letter-spacing: .1em; text-transform: uppercase; color: var(--muted); }
    .big { font-size: 22px; font-weight: 700; color: var(--ink); }
    .labels { display: flex; gap: 2px; font-size: 12px; font-weight: 600; color: var(--ink); }
    .labels span { flex-basis: 0; min-width: 0; text-align: center; }
    .bar {
      display: flex; gap: 2px; height: 18px; border-radius: 4px; overflow: hidden;
      background: var(--surface);
    }
    .seg { flex-basis: 0; min-width: 2px; cursor: default; outline-offset: 2px; }
    .seg:focus-visible { outline: 2px solid var(--ink); }
    .seg-protein, .sw-protein { background: var(--c-protein); }
    .seg-carbs, .sw-carbs { background: var(--c-carbs); }
    .seg-fat, .sw-fat { background: var(--c-fat); }
    .tip { margin: 0; min-height: 1.4em; font-size: 13px; color: var(--muted); }
    /* fixed — чтобы таблица не распирала узкий экран по содержимому ячеек. */
    .legend { border-collapse: collapse; width: 100%; table-layout: fixed; font-size: 14px; }
    .legend th, .legend td { padding: 6px 4px; border-top: 1px solid var(--line); text-align: right; white-space: nowrap; }
    .legend th {
      width: 34%; text-align: left; font-weight: 600; color: var(--ink);
      text-transform: none; letter-spacing: 0; font-size: 14px;
    }
    @media (max-width: 420px) { .legend { font-size: 13px; } .legend th { font-size: 13px; } }
    .legend tr.on th, .legend tr.on td { background: var(--sunken); }
    .sw { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 8px; vertical-align: 0; }
    figcaption { font-size: 12px; }
    .sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
  `],
})
export class NutritionChartComponent {
  readonly protein = input<number | null>(null);
  readonly fat = input<number | null>(null);
  readonly carbs = input<number | null>(null);
  /** Калорийность порции из рецепта; если не задана — считается из БЖУ. */
  readonly calories = input<number | null>(null);
  readonly servings = input(1);

  readonly active = signal<Macro | null>(null);

  readonly slices = computed(() => macroSlices(this.protein() ?? 0, this.carbs() ?? 0, this.fat() ?? 0));

  readonly perPortion = computed(() =>
    this.calories() ?? this.slices().reduce((sum, s) => sum + s.kcal, 0));

  readonly total = computed(() => Math.round(this.perPortion() * this.servings()));

  readonly activeSlice = computed(() => this.slices().find((s) => s.key === this.active()) ?? null);

  servingsText(): string {
    return pluralize(this.servings(), 'порцию', 'порции', 'порций');
  }

  describe(s: MacroSlice): string {
    return `${s.label}: ${s.grams} г, ${s.kcal} ккал, ${s.share}% энергии`;
  }

  summary(): string {
    return 'Калорийность состава: ' + this.slices().map((s) => `${s.label.toLowerCase()} ${s.share}%`).join(', ');
  }
}
