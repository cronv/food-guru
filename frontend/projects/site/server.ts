import { APP_BASE_HREF } from '@angular/common';
import { CommonEngine } from '@angular/ssr/node';
import express from 'express';
import { fileURLToPath } from 'node:url';
import { dirname, join, resolve } from 'node:path';

import bootstrap from './src/main.server';
import { SSR_RESPONSE } from './src/app/services/http-status.service';
import { SSR_API_HEADERS } from './src/app/ssr-api.interceptor';
import { environment } from './src/environments/environment';

/**
 * Angular в режиме разработки запускает проверку размеров картинок
 * (ImagePerformanceWarning). Она пропускает себя, если в окружении нет
 * PerformanceObserver — расчёт на то, что на сервере его не бывает. Node 18+
 * объявляет его глобально, поэтому проверка выполняется, дёргает getDocument()
 * уже после завершения рендера и роняет весь запрос.
 *
 * В боевой сборке этот код вырезается вместе с ngDevMode, так что строки нужны
 * только для `ng build --configuration development`. Серверу рендеринга
 * PerformanceObserver не нужен ни для чего.
 */
const globals = globalThis as unknown as { PerformanceObserver?: unknown };
if (typeof globals.PerformanceObserver !== 'undefined') {
  globals.PerformanceObserver = undefined;
}

/**
 * SSR-сервер публичного сайта.
 * Нужен ради поисковой выдачи и превью ссылок в Telegram: мессенджер читает
 * Open Graph из готового HTML, а не из JavaScript.
 */
export function app(): express.Express {
  const server = express();
  const serverDistFolder = dirname(fileURLToPath(import.meta.url));
  const browserDistFolder = resolve(serverDistFolder, '../browser');
  const indexHtml = join(serverDistFolder, 'index.server.html');
  // При включённом SSR Angular кладёт клиентскую страницу под именем index.csr.html,
  // а не index.html: иначе express.static отдавал бы её вместо рендера.
  const browserIndexHtml = join(browserDistFolder, 'index.csr.html');

  // С Angular 21 движок рендерит только разрешённые хосты, а остальное молча
  // отдаёт клиентской версией — поисковики получили бы пустые страницы.
  // Адрес рендера мы и так собираем из SITE_URL, поэтому разрешаем только его.
  const commonEngine = new CommonEngine({ allowedHosts: [new URL(SITE_URL).hostname] });

  // Перед нами nginx в той же сети: адрес посетителя берём из его X-Forwarded-For.
  // Доверяем только локальным и внутренним сетям — снаружи заголовок не подделать.
  server.set('trust proxy', 'loopback, uniquelocal');
  server.set('view engine', 'html');
  server.set('views', browserDistFolder);

  // robots.txt и sitemap.xml — раньше статики: в них настоящий домен из окружения.
  server.get('/robots.txt', (_req, res) => {
    res.type('text/plain').send(robotsTxt(SITE_URL));
  });

  server.get('/sitemap.xml', async (_req, res) => {
    try {
      res.type('application/xml').set('Cache-Control', 'public, max-age=3600').send(await sitemapXml());
    } catch (err) {
      console.error('sitemap.xml: API недоступен', err);
      res.status(503).set('Retry-After', '600').send('');
    }
  });

  // Статику отдаём напрямую, мимо рендера.
  server.get('*.*', express.static(browserDistFolder, { maxAge: '1y' }));

  server.get('*', (req, res) => {
    const { originalUrl, baseUrl } = req;

    // «//evil.com/x» и «/\evil.com» браузер и HttpClient читают как адрес чужого
    // хоста — на них известная SSRF в Angular SSR. Такие пути не рендерим.
    if (/^\/[/\\]/.test(originalUrl) || originalUrl.includes('\\')) {
      res.redirect(301, '/');
      return;
    }

    commonEngine
      .render({
        bootstrap,
        documentFilePath: indexHtml,
        // Хост — из настроек, а не из заголовка Host: его присылает клиент,
        // и подменённый Host уводил бы относительные запросы рендера на чужой сервер.
        url: `${SITE_URL}${originalUrl}`,
        publicPath: browserDistFolder,
        providers: [
          { provide: APP_BASE_HREF, useValue: baseUrl },
          // Через него страница «не найдено» ставит код 404 — см. HttpStatusService.
          { provide: SSR_RESPONSE, useValue: res },
          // Ключ и адрес посетителя для лимита запросов API — см. ssr-api.interceptor.ts.
          { provide: SSR_API_HEADERS, useValue: SSR_API_KEY ? { 'X-SSR-Key': SSR_API_KEY, 'X-Client-IP': req.ip ?? '' } : null },
        ],
      })
      .then((html) => res.send(html))
      .catch((err: unknown) => {
        // Страховка: если серверный рендер упал, отдаём клиентскую версию
        // вместо страницы ошибки. Человек увидит рабочий сайт — он соберётся
        // в браузере. Потеряется только готовая разметка для поисковиков и
        // превью ссылок, поэтому ошибку обязательно пишем в лог.
        console.error(`SSR не отрендерил ${originalUrl}, отдаём клиентскую версию.`);
        console.error(err);
        res.sendFile(browserIndexHtml);
      });
  });

  return server;
}

/* ---------- SEO: robots.txt и sitemap.xml ---------- */

/** Домен сайта и адрес API. В проде задаются переменными окружения, иначе — из environment. */
const SITE_URL = (process.env['SITE_URL'] || environment.siteUrl).replace(/\/$/, '');
// Внутренний адрес API для сервера (например, http://api:8000 в docker), если отличается от публичного.
const API_URL = (process.env['API_INTERNAL_URL'] || environment.apiBaseUrl).replace(/\/$/, '');
// Общий с API секрет (SSR_API_KEY в backend/.env). Без него лимит API — один на всех посетителей.
const SSR_API_KEY = process.env['SSR_API_KEY'] || '';
if (!SSR_API_KEY) {
  console.warn('SSR_API_KEY не задан: под нагрузкой API начнёт отвечать 429 на серверный рендер.');
}

function robotsTxt(site: string): string {
  return [
    'User-agent: *',
    // Личное, платное и одноразовое — не для выдачи.
    'Disallow: /account',
    'Disallow: /cart',
    'Disallow: /wallet',
    'Disallow: /orders',
    'Disallow: /cookbook',
    'Disallow: /login/',
    'Disallow: /verify/',
    'Disallow: /books/*/read/',
    '',
    // Яндекс: метки рекламных кампаний не создают отдельных страниц.
    'User-agent: Yandex',
    'Clean-param: utm_source&utm_medium&utm_campaign&utm_content&utm_term&yclid&gclid',
    'Disallow: /account',
    'Disallow: /cart',
    'Disallow: /wallet',
    'Disallow: /orders',
    'Disallow: /cookbook',
    'Disallow: /login/',
    'Disallow: /verify/',
    'Disallow: /books/*/read/',
    '',
    `Sitemap: ${site}/sitemap.xml`,
    '',
  ].join('\n');
}

interface SitemapUrl { path: string; lastmod: string | null; priority: number; changefreq: string }

let sitemapCache: { xml: string; at: number } | null = null;

/** Список адресов берём у API и кэшируем на час: робот может ходить часто. */
async function sitemapXml(): Promise<string> {
  if (sitemapCache && Date.now() - sitemapCache.at < 3_600_000) return sitemapCache.xml;

  const res = await fetch(`${API_URL}/api/v1/seo/sitemap`, { headers: { Accept: 'application/json' } });
  if (!res.ok) throw new Error(`API ответил ${res.status}`);
  const { data } = (await res.json()) as { data: SitemapUrl[] };

  const esc = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const body = data.map((u) => [
    '  <url>',
    `    <loc>${esc(SITE_URL + (u.path === '/' ? '/' : u.path))}</loc>`,
    u.lastmod ? `    <lastmod>${u.lastmod}</lastmod>` : '',
    `    <changefreq>${u.changefreq}</changefreq>`,
    `    <priority>${u.priority.toFixed(1)}</priority>`,
    '  </url>',
  ].filter(Boolean).join('\n')).join('\n');

  const xml = `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${body}\n</urlset>\n`;
  sitemapCache = { xml, at: Date.now() };
  return xml;
}

function run(): void {
  const port = Number(process.env['PORT']) || 4000;
  const server = app();
  server.listen(port, () => {
    console.log(`SSR слушает http://localhost:${port}`);
  });
}

run();
