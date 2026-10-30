// Иллюстрации готовых блюд, нарисованные кодом. Фотосток не используется:
// права на графику ваши.
//
//   node tools/dishes/make_dishes.mjs
//
// На выходе по три SVG на рецепт — «готовое блюдо», «ингредиенты» и «в процессе» —
// в backend/database/seeders/assets/dishes. Сидер кладёт их в медиатеку.
//
// Генератор перенесён из прототипа (site/index.html, функция dish): посуда,
// заливка и россыпь элементов. Единственное отличие — цвета вписаны значениями,
// потому что в отдельном файле CSS-переменных страницы нет.

import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const outDir = join(root, 'backend', 'database', 'seeders', 'assets', 'dishes');

const PALETTE = {
  sunken: '#F4F1EA', brick: '#B4451F',
  'choc-d': '#3A2014', choc: '#6B3F22', gold: '#D9A24E', plate: '#EFE7D8',
  pan: '#55555A', shrimp: '#E08A5F', tomato: '#C4452A', basil: '#4E7A46', noodle: '#E8C77E',
  pumpkin: '#D98A3A', beet: '#9B2F55', feta: '#F2EDE2', yolk: '#E6A93C', mush: '#BFA98A',
  broth: '#C9752E', berry: '#8E2B4A', sourcream: '#F4F0E6',
};

/** Фон кадра: тёплые «столешницы», чередуются от блюда к блюду. */
const BACKDROPS = [['#E7E0D2', '#D9CFBC'], ['#E3E6DC', '#D2D8C6'], ['#EEE3D6', '#E0D0BC'], ['#E6E2DB', '#D6D0C4']];

const resolve = (s) => s.replace(/var\(--([a-z-]+)\)/g, (_, name) => PALETTE[name] ?? '#888888');

let uid = 0;

function scatter(n, cx, cy, rx, ry, seed) {
  const out = [];
  for (let i = 0; i < n; i++) {
    const a = i * 2.39996 + seed;
    const r = Math.sqrt((i + 0.6) / n) * 0.8;
    out.push([cx + Math.cos(a) * rx * r, cy + Math.sin(a) * ry * r, (a * 57) % 360]);
  }
  return out;
}

function dish(cfg, backdrop) {
  const id = 'g' + ++uid;
  const vessel = cfg.vessel || 'bowl';
  const base = cfg.base || 'var(--broth)';
  const items = cfg.items || [];
  const label = cfg.label || 'Иллюстрация блюда';

  const G = {
    bowl: { cx: 200, cy: 148, rx: 118, ry: 27, rim: [140, 34] },
    plate: { cx: 200, cy: 166, rx: 116, ry: 32, rim: [152, 44] },
    pan: { cx: 200, cy: 162, rx: 116, ry: 82, rim: [150, 110] },
  }[vessel];

  let body;
  if (vessel === 'bowl') {
    body = `
      <path d="M60 148a140 140 0 0 0 280 0z" fill="url(#${id}v)"/>
      <ellipse cx="200" cy="148" rx="${G.rim[0]}" ry="${G.rim[1]}" fill="url(#${id}r)"/>
      <ellipse cx="200" cy="148" rx="${G.rx}" ry="${G.ry}" fill="url(#${id}b)"/>
      <ellipse cx="146" cy="214" rx="34" ry="46" fill="#fff" opacity=".1" filter="url(#${id}s)"/>`;
  } else if (vessel === 'plate') {
    body = `
      <ellipse cx="200" cy="166" rx="${G.rim[0]}" ry="${G.rim[1]}" fill="url(#${id}r)"/>
      <ellipse cx="200" cy="166" rx="124" ry="36" fill="url(#${id}v)"/>
      <ellipse cx="200" cy="166" rx="${G.rx}" ry="${G.ry}" fill="url(#${id}b)"/>
      <ellipse cx="128" cy="160" rx="38" ry="10" fill="#fff" opacity=".2" filter="url(#${id}s)"/>`;
  } else {
    body = `
      <ellipse cx="200" cy="162" rx="150" ry="112" fill="url(#${id}v)"/>
      <ellipse cx="200" cy="164" rx="131" ry="96" fill="var(--sunken)"/>
      <ellipse cx="200" cy="164" rx="${G.rx}" ry="${G.ry}" fill="url(#${id}b)"/>
      <ellipse cx="146" cy="110" rx="48" ry="22" fill="#fff" opacity=".1" filter="url(#${id}s)"/>`;
  }

  let deco = '';
  items.forEach((it, k) => {
    const spread = it.spread || 0.82;
    const pts = scatter(it.n || 5, G.cx, G.cy, G.rx * spread, G.ry * spread, (k + 1) * 1.7);
    const s = it.size || 7;
    for (const [x, y, a] of pts) {
      const X = x.toFixed(1), Y = y.toFixed(1), A = a.toFixed(0);
      if (it.type === 'leaf') {
        deco += `<g transform="rotate(${A} ${X} ${Y})">
          <ellipse cx="${X}" cy="${Y}" rx="${s * 1.7}" ry="${s * 0.8}" fill="${it.color}"/>
          <line x1="${(x - s * 1.4).toFixed(1)}" y1="${Y}" x2="${(x + s * 1.4).toFixed(1)}" y2="${Y}" stroke="#fff" stroke-opacity=".3" stroke-width="1"/></g>`;
      } else if (it.type === 'chunk') {
        deco += `<g transform="rotate(${A} ${X} ${Y})">
          <rect x="${(x - s).toFixed(1)}" y="${(y - s * 0.7).toFixed(1)}" width="${s * 2}" height="${s * 1.4}" rx="3" fill="${it.color}"/>
          <rect x="${(x - s).toFixed(1)}" y="${(y - s * 0.7).toFixed(1)}" width="${s * 2}" height="${s * 0.5}" rx="3" fill="#fff" opacity=".22"/></g>`;
      } else if (it.type === 'disc') {
        deco += `<ellipse cx="${X}" cy="${Y}" rx="${s * 2}" ry="${s * 1.5}" fill="${it.color}"/>
          <ellipse cx="${(x - s * 0.5).toFixed(1)}" cy="${(y - s * 0.4).toFixed(1)}" rx="${s * 0.8}" ry="${s * 0.45}" fill="#fff" opacity=".3"/>`;
      } else if (it.type === 'swirl') {
        deco += `<path d="M${(x - s * 2.4).toFixed(1)} ${Y}q${s * 1.2} ${-s * 1.4} ${s * 2.4} 0t${s * 2.4} 0" fill="none" stroke="${it.color}" stroke-width="${Math.max(2, s * 0.6)}" stroke-linecap="round" opacity=".9"/>`;
      } else {
        deco += `<circle cx="${X}" cy="${Y}" r="${s}" fill="${it.color}"/>
          <circle cx="${(x - s * 0.3).toFixed(1)}" cy="${(y - s * 0.3).toFixed(1)}" r="${s * 0.3}" fill="#fff" opacity=".35"/>`;
      }
    }
  });

  const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 300" width="1200" height="900" role="img" aria-label="${label}">
    <defs>
      <linearGradient id="${id}bg" x1="0" y1="0" x2="1" y2="1">
        <stop offset="0" stop-color="${backdrop[0]}"/><stop offset="1" stop-color="${backdrop[1]}"/>
      </linearGradient>
      <linearGradient id="${id}v" x1="0" y1="0" x2=".4" y2="1">
        <stop offset="0" stop-color="var(--plate)"/><stop offset=".45" stop-color="var(--plate)"/><stop offset="1" stop-color="var(--choc-d)"/>
      </linearGradient>
      <linearGradient id="${id}r" x1="0" y1="0" x2="1" y2="1">
        <stop offset="0" stop-color="#fff" stop-opacity=".5"/><stop offset=".4" stop-color="var(--plate)"/><stop offset="1" stop-color="var(--sunken)"/>
      </linearGradient>
      <radialGradient id="${id}b" cx=".36" cy=".3" r=".85">
        <stop offset="0" stop-color="${base}"/><stop offset=".7" stop-color="${base}"/><stop offset="1" stop-color="var(--choc-d)" stop-opacity=".5"/>
      </radialGradient>
      <filter id="${id}s" x="-40%" y="-40%" width="180%" height="180%"><feGaussianBlur stdDeviation="6"/></filter>
      <filter id="${id}d" x="-40%" y="-40%" width="180%" height="180%"><feGaussianBlur stdDeviation="9"/></filter>
    </defs>
    <rect width="400" height="300" fill="url(#${id}bg)"/>
    <ellipse cx="212" cy="${vessel === 'bowl' ? 250 : 206}" rx="150" ry="${vessel === 'pan' ? 100 : 30}" fill="#000" opacity=".32" filter="url(#${id}d)"/>
    ${body}
    ${deco}
  </svg>`;

  return resolve(svg).replace(/\n\s*\n/g, '\n');
}

/** Кадр «ингредиенты»: всё разложено на доске, элементов больше и шире. */
const ingredients = (spec, backdrop) => dish({
  vessel: 'plate', base: 'var(--sunken)',
  label: 'Ингредиенты, разложенные перед приготовлением',
  items: spec.items.map((it) => ({ ...it, spread: 0.95, n: Math.min(9, (it.n || 4) + 3), size: (it.size || 6) * 0.9 })),
}, backdrop);

/** Кадр «в процессе»: всё в сковороде, элементов меньше и ближе к центру. */
const process = (spec, backdrop) => dish({
  vessel: 'pan', base: spec.base,
  label: 'Блюдо в процессе приготовления',
  items: spec.items.slice(0, 2).map((it) => ({ ...it, spread: 0.6, n: Math.max(3, (it.n || 4) - 1) })),
}, backdrop);

/* Спецификации блюд: из одной получаются все три кадра. */
const SPECS = {
  'tykvennyy-krem-sup': {
    vessel: 'bowl', base: 'var(--pumpkin)', label: 'Тыквенный крем-суп с нутом в миске',
    items: [{ type: 'swirl', color: 'var(--sourcream)', n: 2, size: 6, spread: 0.5 },
            { type: 'dot', color: 'var(--noodle)', n: 11, size: 6 },
            { type: 'leaf', color: 'var(--basil)', n: 2, size: 5, spread: 0.6 }],
  },
  'shokoladnyy-fondan': {
    vessel: 'plate', base: 'var(--choc)', label: 'Шоколадный фондан на тарелке',
    items: [{ type: 'disc', color: 'var(--choc-d)', n: 2, size: 16, spread: 0.4 },
            { type: 'dot', color: 'var(--gold)', n: 5, size: 5, spread: 0.7 },
            { type: 'dot', color: 'var(--berry)', n: 4, size: 6, spread: 0.95 }],
  },
  'pasta-krevetki-tomat': {
    vessel: 'pan', base: 'var(--tomato)', label: 'Паста с креветками на сковороде',
    items: [{ type: 'swirl', color: 'var(--noodle)', n: 4, size: 8, spread: 0.6 },
            { type: 'chunk', color: 'var(--shrimp)', n: 4, size: 10, spread: 0.75 },
            { type: 'leaf', color: 'var(--basil)', n: 3, size: 6, spread: 0.9 }],
  },
  'pasta-cacio-e-pepe': {
    vessel: 'plate', base: 'var(--noodle)', label: 'Паста качо-э-пепе на тарелке',
    items: [{ type: 'swirl', color: 'var(--gold)', n: 5, size: 7, spread: 0.6 },
            { type: 'dot', color: 'var(--choc-d)', n: 9, size: 3, spread: 0.85 },
            { type: 'chunk', color: 'var(--feta)', n: 3, size: 5, spread: 0.5 }],
  },
  'syrniki-risovaya-muka': {
    vessel: 'plate', base: 'var(--sourcream)', label: 'Сырники на тарелке со сметаной и ягодами',
    items: [{ type: 'disc', color: 'var(--noodle)', n: 3, size: 13, spread: 0.55 },
            { type: 'dot', color: 'var(--berry)', n: 6, size: 7, spread: 0.95 },
            { type: 'leaf', color: 'var(--basil)', n: 1, size: 5, spread: 0.3 }],
  },
  'kinoa-svekla-feta': {
    vessel: 'bowl', base: 'var(--noodle)', label: 'Киноа с печёной свёклой и фетой в миске',
    items: [{ type: 'chunk', color: 'var(--beet)', n: 6, size: 9 },
            { type: 'chunk', color: 'var(--feta)', n: 5, size: 7, spread: 0.65 },
            { type: 'dot', color: 'var(--mush)', n: 5, size: 4, spread: 0.9 },
            { type: 'leaf', color: 'var(--basil)', n: 2, size: 5, spread: 0.5 }],
  },
  'bedra-medovo-gorchichnye': {
    vessel: 'pan', base: 'var(--brick)', label: 'Куриные бёдра в медово-горчичном соусе',
    items: [{ type: 'chunk', color: 'var(--choc)', n: 4, size: 18, spread: 0.6 },
            { type: 'dot', color: 'var(--gold)', n: 7, size: 5, spread: 0.95 },
            { type: 'leaf', color: 'var(--basil)', n: 3, size: 6, spread: 0.9 }],
  },
  'shakshuka-s-fetoy': {
    vessel: 'pan', base: 'var(--tomato)', label: 'Шакшука на сковороде с яйцами и фетой',
    items: [{ type: 'disc', color: 'var(--feta)', n: 2, size: 15, spread: 0.45 },
            { type: 'dot', color: 'var(--yolk)', n: 2, size: 12, spread: 0.45 },
            { type: 'chunk', color: 'var(--feta)', n: 5, size: 6, spread: 0.95 },
            { type: 'leaf', color: 'var(--basil)', n: 3, size: 6, spread: 0.85 }],
  },
  'chechevichnyy-sup': {
    vessel: 'bowl', base: 'var(--broth)', label: 'Чечевичный суп с томатами в миске',
    items: [{ type: 'dot', color: 'var(--tomato)', n: 8, size: 6 },
            { type: 'dot', color: 'var(--noodle)', n: 7, size: 4, spread: 0.9 },
            { type: 'leaf', color: 'var(--basil)', n: 2, size: 5, spread: 0.45 }],
  },
  'gribnoy-sup-slivki': {
    vessel: 'bowl', base: 'var(--sourcream)', label: 'Грибной крем-суп со сливками в миске',
    items: [{ type: 'chunk', color: 'var(--mush)', n: 6, size: 8, spread: 0.7 },
            { type: 'swirl', color: 'var(--feta)', n: 2, size: 6, spread: 0.4 },
            { type: 'leaf', color: 'var(--basil)', n: 2, size: 4, spread: 0.8 }],
  },
  // Эти четыре в прототипе без иллюстраций — добавлены в том же стиле.
  'hachapuri-adzharski': {
    vessel: 'plate', base: 'var(--feta)', label: 'Хачапури по-аджарски с яйцом и маслом',
    items: [{ type: 'chunk', color: 'var(--noodle)', n: 6, size: 12, spread: 0.9 },
            { type: 'dot', color: 'var(--yolk)', n: 1, size: 14, spread: 0.1 },
            { type: 'chunk', color: 'var(--gold)', n: 2, size: 5, spread: 0.3 }],
  },
  'pankeyki-na-kefire': {
    vessel: 'plate', base: 'var(--noodle)', label: 'Панкейки на кефире с ягодами',
    items: [{ type: 'disc', color: 'var(--gold)', n: 3, size: 14, spread: 0.4 },
            { type: 'dot', color: 'var(--berry)', n: 6, size: 6, spread: 0.9 },
            { type: 'swirl', color: 'var(--sourcream)', n: 2, size: 6, spread: 0.45 }],
  },
  'tiramisu': {
    vessel: 'plate', base: 'var(--sourcream)', label: 'Тирамису с какао на тарелке',
    items: [{ type: 'chunk', color: 'var(--choc)', n: 3, size: 14, spread: 0.45 },
            { type: 'dot', color: 'var(--choc-d)', n: 10, size: 3, spread: 0.85 },
            { type: 'leaf', color: 'var(--basil)', n: 1, size: 4, spread: 0.2 }],
  },
  'borshch-s-govyadinoy': {
    vessel: 'bowl', base: 'var(--tomato)', label: 'Борщ с говядиной и сметаной в миске',
    items: [{ type: 'swirl', color: 'var(--sourcream)', n: 2, size: 7, spread: 0.35 },
            { type: 'chunk', color: 'var(--choc)', n: 4, size: 7, spread: 0.7 },
            { type: 'leaf', color: 'var(--basil)', n: 3, size: 4, spread: 0.8 }],
  },
};

mkdirSync(outDir, { recursive: true });

let count = 0;
Object.entries(SPECS).forEach(([slug, spec], i) => {
  const backdrop = BACKDROPS[i % BACKDROPS.length];
  const frames = { cover: dish(spec, backdrop), ingredients: ingredients(spec, backdrop), process: process(spec, backdrop) };
  for (const [kind, svg] of Object.entries(frames)) {
    writeFileSync(join(outDir, `${slug}-${kind}.svg`), svg, 'utf8');
    count++;
  }
});

console.log(`Готово: ${count} файлов в ${outDir}`);
