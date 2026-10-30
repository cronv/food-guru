import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { API_BASE_URL } from '@shared/tokens';
import { SSR_API_HEADERS, ssrApiInterceptor } from './ssr-api.interceptor';

describe('ssrApiInterceptor', () => {
  function setup(headers: Record<string, string> | null) {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([ssrApiInterceptor])),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.food-guru.ru' },
        { provide: SSR_API_HEADERS, useValue: headers },
      ],
    });
    return { http: TestBed.inject(HttpClient), ctrl: TestBed.inject(HttpTestingController) };
  }

  it('на сервере добавляет ключ и адрес посетителя к запросам в наш API', () => {
    const { http, ctrl } = setup({ 'X-SSR-Key': 'k', 'X-Client-IP': '203.0.113.7' });
    http.get('https://api.food-guru.ru/api/v1/home').subscribe();

    const req = ctrl.expectOne('https://api.food-guru.ru/api/v1/home');
    expect(req.request.headers.get('X-SSR-Key')).toBe('k');
    expect(req.request.headers.get('X-Client-IP')).toBe('203.0.113.7');
  });

  it('секрет не уходит на чужие адреса, даже похожие на наш', () => {
    const { http, ctrl } = setup({ 'X-SSR-Key': 'k', 'X-Client-IP': '203.0.113.7' });
    http.get('https://api.food-guru.ru.evil.com/x').subscribe();

    expect(ctrl.expectOne('https://api.food-guru.ru.evil.com/x').request.headers.has('X-SSR-Key')).toBeFalse();
  });

  it('в браузере ничего не добавляет', () => {
    const { http, ctrl } = setup(null);
    http.get('https://api.food-guru.ru/api/v1/home').subscribe();

    expect(ctrl.expectOne('https://api.food-guru.ru/api/v1/home').request.headers.has('X-SSR-Key')).toBeFalse();
  });
});
