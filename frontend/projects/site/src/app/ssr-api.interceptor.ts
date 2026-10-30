import { HttpInterceptorFn } from '@angular/common/http';
import { InjectionToken, inject } from '@angular/core';

import { API_BASE_URL } from '@shared/tokens';

/**
 * Заголовки для API на время серверного рендера: ключ SSR и адрес посетителя.
 * Задаёт server.ts на каждый запрос; в браузере токена нет, и перехватчик молчит.
 */
export const SSR_API_HEADERS = new InjectionToken<Record<string, string> | null>('SSR_API_HEADERS');

/**
 * SSR ходит в API за всех посетителей с одного IP. Без ключа API считает это одним
 * клиентом и после 60 запросов в минуту отвечает 429 — страницы ломаются у всех.
 * Ключ уходит только на наш API: на чужие адреса секрет не отправляем.
 */
export const ssrApiInterceptor: HttpInterceptorFn = (req, next) => {
  const headers = inject(SSR_API_HEADERS, { optional: true });
  const api = inject(API_BASE_URL);

  if (!headers || !req.url.startsWith(api + '/')) {
    return next(req);
  }

  return next(req.clone({ setHeaders: headers }));
};
