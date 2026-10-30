import { ApplicationConfig, provideZoneChangeDetection } from '@angular/core';
import { provideHttpClient, withFetch, withInterceptors } from '@angular/common/http';
import { provideRouter, withComponentInputBinding } from '@angular/router';

import { xsrfInterceptor } from '@shared/services/xsrf.interceptor';
import { API_BASE_URL, SITE_URL } from '@shared/tokens';
import { environment } from '../environments/environment';
import { routes } from './app.routes';

export const appConfig: ApplicationConfig = {
  providers: [
    provideZoneChangeDetection({ eventCoalescing: true }),
    provideRouter(routes, withComponentInputBinding()),
    provideHttpClient(withFetch(), withInterceptors([xsrfInterceptor])),
    { provide: API_BASE_URL, useValue: environment.apiBaseUrl },
    { provide: SITE_URL, useValue: environment.siteUrl },
  ],
};
