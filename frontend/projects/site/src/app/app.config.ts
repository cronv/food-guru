import { ApplicationConfig, provideZoneChangeDetection } from '@angular/core';
import { provideHttpClient, withFetch, withInterceptors } from '@angular/common/http';
import { provideClientHydration, withHttpTransferCacheOptions } from '@angular/platform-browser';
import { provideRouter, withComponentInputBinding, withInMemoryScrolling } from '@angular/router';

import { xsrfInterceptor } from '@shared/services/xsrf.interceptor';
import { API_BASE_URL, SITE_URL } from '@shared/tokens';
import { environment } from '../environments/environment';
import { routes } from './app.routes';
import { ssrApiInterceptor } from './ssr-api.interceptor';
import { isPublicApiRequest } from './transfer-cache';

export const appConfig: ApplicationConfig = {
  providers: [
    provideZoneChangeDetection({ eventCoalescing: true }),

    // Наверх при переходе вперёд, прежняя позиция при «Назад», прокрутка к якорю.
    provideRouter(
      routes,
      withComponentInputBinding(),
      withInMemoryScrolling({ scrollPositionRestoration: 'enabled', anchorScrolling: 'enabled' }),
    ),

    // withFetch обязателен для SSR: на HttpClient без него нет серверной реализации.
    // Перехватчик ставит X-XSRF-TOKEN: встроенный в Angular делает это только
    // для относительных адресов, а API на другом домене — без него будет 419.
    // ssrApiInterceptor работает только на сервере — см. ssr-api.interceptor.ts.
    provideHttpClient(withFetch(), withInterceptors([xsrfInterceptor, ssrApiInterceptor])),

    // Гидратация: браузер подхватывает готовую разметку сервера, а не рисует
    // страницу заново, поэтому нет мерцания при загрузке. Ответы API сервер
    // передаёт вместе с HTML, но только общие для всех — см. transfer-cache.ts.
    provideClientHydration(withHttpTransferCacheOptions({ filter: isPublicApiRequest })),

    { provide: API_BASE_URL, useValue: environment.apiBaseUrl },
    { provide: SITE_URL, useValue: environment.siteUrl },
  ],
};
