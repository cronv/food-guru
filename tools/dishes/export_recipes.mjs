// Выгружает рецепты из прототипа (site/index.html) в JSON для сидера.
//
//   node tools/dishes/export_recipes.mjs
//
// В прототипе лежат настоящие тексты: ингредиенты с граммовками, шаги,
// подсказки с температурой и временем. Сидер читает этот файл вместо заглушек.

import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const html = readFileSync(join(root, 'site', 'index.html'), 'utf8');

const from = html.indexOf('const R = (slug');
const to = html.indexOf('const PUBLISHED');
if (from < 0 || to < 0) throw new Error('Не нашёл RECIPES в site/index.html');

const recipes = new Function(`${html.slice(from, to)}\nreturn RECIPES;`)();

const DIFFICULTY = { 'Просто': 'easy', 'Средне': 'medium', 'Сложно': 'hard' };

const out = recipes.map((r) => ({
  slug: r.slug,
  title: r.title,
  category: r.cat,
  minutes: r.min,
  servings: r.serv,
  difficulty: DIFFICULTY[r.diff] ?? 'easy',
  lead: r.lead,
  tags: r.tags,
  vegetarian: r.diet.includes('veg'),
  gluten_free: r.diet.includes('gf'),
  nutrition: { calories: r.kb[0], protein: r.kb[1], fat: r.kb[2], carbs: r.kb[3] },
  // Количество указано на одну порцию, как и в базе.
  ingredients: r.ing.map(([name, amount, unit]) => ({ name, amount, unit })),
  // Шаг — строка или пара [текст, подсказка].
  steps: r.steps.map((s) => (Array.isArray(s) ? { body: s[0], hint: s[1] } : { body: s, hint: null })),
  status: r.status,
  reel: r.reel ?? null,
}));

const dir = join(root, 'backend', 'database', 'seeders', 'assets');
mkdirSync(dir, { recursive: true });
writeFileSync(join(dir, 'recipes.json'), JSON.stringify(out, null, 2), 'utf8');

console.log(`Выгружено рецептов: ${out.length}`);
