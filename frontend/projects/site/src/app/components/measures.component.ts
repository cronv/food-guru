import { Component } from '@angular/core';

export interface Measure {
  name: string;
  ml: string;
  note?: string;
}

/** Объёмы — по ГОСТ и общепринятым кулинарным таблицам; вес продуктов не приводим, он зависит от продукта. */
export const MEASURES: Measure[] = [
  { name: 'Капля', ml: '0,05 мл' },
  { name: 'Щепотка', ml: '≈ 0,3 мл', note: 'сколько захватят три пальца' },
  { name: 'Кофейная ложка', ml: '2,5 мл' },
  { name: 'Чайная ложка', ml: '5 мл' },
  { name: 'Десертная ложка', ml: '10 мл' },
  { name: 'Столовая ложка', ml: '15 мл' },
  { name: 'Жидкая унция', ml: '≈ 30 мл', note: 'fl oz в зарубежных рецептах' },
  { name: 'Рюмка', ml: '50 мл' },
  { name: 'Стакан гранёный', ml: '200 мл', note: 'до ободка; доверху — 250 мл' },
  { name: 'Стакан тонкостенный', ml: '250 мл' },
  { name: 'Чашка (cup)', ml: '240 мл', note: 'американская мера' },
  { name: 'Пинта', ml: '≈ 470 мл', note: 'американская; британская — 568 мл' },
  { name: 'Литр', ml: '1000 мл' },
];

@Component({
  selector: 'app-measures',
  standalone: true,
  template: `
    <details class="box">
      <summary>
        <span>Таблица мер: ложки, стаканы, чашки</span>
        <svg class="chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
      </summary>
      <table>
        <caption class="sr">Перевод бытовых мер в миллилитры</caption>
        <thead><tr><th scope="col">Мера</th><th scope="col">Объём</th></tr></thead>
        <tbody>
          @for (m of measures; track m.name) {
            <tr>
              <th scope="row">{{ m.name }}@if (m.note) { <span class="note">{{ m.note }}</span> }</th>
              <td class="tnum">{{ m.ml }}</td>
            </tr>
          }
        </tbody>
      </table>
      <p class="muted foot">Ложки — без горки. С горкой столовая ложка вмещает примерно вдвое больше сыпучего.</p>
    </details>
  `,
  styles: [`
    .box { background: var(--surface); border: 1px solid var(--line); border-radius: 14px; }
    summary {
      list-style: none; cursor: pointer; min-height: 44px; padding: 0 14px;
      display: flex; align-items: center; justify-content: space-between; gap: 10px; font-weight: 600;
    }
    summary::-webkit-details-marker { display: none; }
    .chev { transition: transform .15s; flex: 0 0 auto; }
    details[open] .chev { transform: rotate(180deg); }
    /* fixed и перенос в названиях: колонка узкая, таблица не должна распирать страницу. */
    table { width: 100%; border-collapse: collapse; font-size: 14px; table-layout: fixed; }
    th, td { padding: 8px 14px; border-top: 1px solid var(--line); text-align: left; vertical-align: top; }
    th { white-space: normal; overflow-wrap: anywhere; }
    th:first-child { width: 64%; }
    thead th { font-size: 11px; letter-spacing: .1em; text-transform: uppercase; color: var(--muted); font-weight: 600; }
    tbody th { font-weight: 600; }
    td { text-align: right; white-space: nowrap; font-weight: 700; }
    thead th:last-child { text-align: right; }
    .note { display: block; font-weight: 400; font-size: 12px; color: var(--muted); }
    .foot { margin: 0; padding: 10px 14px 14px; font-size: 12px; }
    .sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
    @media (prefers-reduced-motion: reduce) { .chev { transition: none; } }
  `],
})
export class MeasuresComponent {
  readonly measures = MEASURES;
}
