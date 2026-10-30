
import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { DOCUMENT } from '@angular/core';
import { TestBed } from '@angular/core/testing';

import { API_BASE_URL } from '../tokens';
import { xsrfInterceptor } from './xsrf.interceptor';

/**
 * Без заголовка сервер отвечает 419 на любое действие. Ошибка не видна
 * в тестах API-клиента: там проверяется только запрос cookie, а не заголовок.
 */
describe('xsrfInterceptor', () => {
  const BASE = 'http://localhost:8000';
  let http: HttpClient;
  let ctrl: HttpTestingController;
  let cookie: string;

  beforeEach(() => {
    cookie = 'other=1; XSRF-TOKEN=abc%3D%3D; last=2';
    const fakeDocument = { get cookie() { return cookie; } };

    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([xsrfInterceptor])),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: BASE },
        { provide: DOCUMENT, useValue: fakeDocument },
      ],
    });
    http = TestBed.inject(HttpClient);
    ctrl = TestBed.inject(HttpTestingController);
  });

  afterEach(() => ctrl.verify());

  it('изменяющий запрос к API несёт токен из cookie, раскодированный', () => {
    http.post(`${BASE}/api/v1/auth/login`, {}).subscribe();
    expect(ctrl.expectOne(`${BASE}/api/v1/auth/login`).request.headers.get('X-XSRF-TOKEN')).toBe('abc==');
  });

  it('GET токен не несёт', () => {
    http.get(`${BASE}/api/v1/home`).subscribe();
    expect(ctrl.expectOne(`${BASE}/api/v1/home`).request.headers.has('X-XSRF-TOKEN')).toBeFalse();
  });

  it('на чужой адрес токен не уходит', () => {
    http.post('https://evil.example/steal', {}).subscribe();
    expect(ctrl.expectOne('https://evil.example/steal').request.headers.has('X-XSRF-TOKEN')).toBeFalse();
  });

  it('без cookie запрос уходит как есть', () => {
    cookie = '';
    http.delete(`${BASE}/api/v1/me/favorites/1`).subscribe();
    expect(ctrl.expectOne(`${BASE}/api/v1/me/favorites/1`).request.headers.has('X-XSRF-TOKEN')).toBeFalse();
  });
});
