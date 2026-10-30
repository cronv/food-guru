
import { HttpInterceptorFn } from '@angular/common/http';
import { inject, DOCUMENT } from '@angular/core';

import { API_BASE_URL } from '../tokens';

const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

/**
 * Кладёт XSRF-cookie Sanctum в заголовок X-XSRF-TOKEN.
 *
 * Встроенный перехватчик Angular делает это только для относительных адресов,
 * а API живёт на своём поддомене (локально — на своём порту), и все запросы
 * к нему абсолютные. Без этого заголовка Laravel отвечает 419 на любой
 * изменяющий запрос: вход, отзыв, голос, сохранение в админке.
 *
 * Cookie читается из document.cookie: она выставлена на общий домен
 * (SESSION_DOMAIN), поэтому видна и сайту, и админке.
 */
export const xsrfInterceptor: HttpInterceptorFn = (req, next) => {
  const base = inject(API_BASE_URL);
  const doc = inject(DOCUMENT);

  if (SAFE_METHODS.includes(req.method) || !req.url.startsWith(base) || req.headers.has('X-XSRF-TOKEN')) {
    return next(req);
  }

  const token = readCookie(doc.cookie ?? '', 'XSRF-TOKEN');
  return next(token ? req.clone({ setHeaders: { 'X-XSRF-TOKEN': token } }) : req);
};

function readCookie(cookies: string, name: string): string | null {
  for (const part of cookies.split(';')) {
    const [key, ...rest] = part.trim().split('=');
    if (key === name) return decodeURIComponent(rest.join('='));
  }
  return null;
}
