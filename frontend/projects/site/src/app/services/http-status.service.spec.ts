import { HttpErrorResponse } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { Meta } from '@angular/platform-browser';

import { HttpStatusService, SSR_RESPONSE } from './http-status.service';

describe('HttpStatusService.fromError', () => {
  let codes: number[];
  let service: HttpStatusService;
  let meta: Meta;

  beforeEach(() => {
    codes = [];
    TestBed.configureTestingModule({
      providers: [{ provide: SSR_RESPONSE, useValue: { status: (code: number) => codes.push(code) } }],
    });
    service = TestBed.inject(HttpStatusService);
    meta = TestBed.inject(Meta);
    meta.removeTag('name="robots"');
  });

  it('API ответил 404 — страница не найдена и закрыта от индексации', () => {
    service.fromError(new HttpErrorResponse({ status: 404 }));

    expect(codes).toEqual([404]);
    expect(meta.getTag('name="robots"')?.content).toBe('noindex, nofollow');
  });

  it('API недоступен — 503 без noindex, чтобы простой не выкинул страницу из поиска', () => {
    service.fromError(new HttpErrorResponse({ status: 0 }));
    service.fromError(new HttpErrorResponse({ status: 502 }));

    expect(codes).toEqual([503, 503]);
    expect(meta.getTag('name="robots"')).toBeNull();
  });
});
