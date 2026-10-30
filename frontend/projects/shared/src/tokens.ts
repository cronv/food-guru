import { InjectionToken } from '@angular/core';

/**
 * Базовый адрес API без завершающего слэша, например https://api.example.com.
 * Задаётся в app.config каждого приложения из environment.
 */
export const API_BASE_URL = new InjectionToken<string>('API_BASE_URL');

/** Адрес публичного сайта — нужен админке для ссылок «посмотреть на сайте». */
export const SITE_URL = new InjectionToken<string>('SITE_URL');
