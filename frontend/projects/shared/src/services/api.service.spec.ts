import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';

import { ApiService } from './api.service';
import { API_BASE_URL } from '../tokens';

describe('ApiService', () => {
  let api: ApiService;
  let http: HttpTestingController;

  const BASE = 'https://api.food-guru.test';

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: BASE },
      ],
    });

    api = TestBed.inject(ApiService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('собирает адрес с префиксом версии', () => {
    api.recipes().subscribe();

    const req = http.expectOne((r) => r.url === `${BASE}/api/v1/recipes`);
    expect(req.request.method).toBe('GET');
    // Сессия Sanctum живёт в cookie: без этого флага сервер не узнает человека.
    expect(req.request.withCredentials).toBeTrue();
  });

  it('пустые значения фильтра в запрос не попадают', () => {
    api.recipes({ q: '', category: 'supy', max_time: undefined }).subscribe();

    const req = http.expectOne((r) => r.url.endsWith('/recipes'));
    expect(req.request.params.get('category')).toBe('supy');
    expect(req.request.params.has('q')).toBeFalse();
    expect(req.request.params.has('max_time')).toBeFalse();
  });

  it('массивы уходят повторяющимся параметром со скобками', () => {
    api.recipes({ tags: ['pasta', 'bystro'] }).subscribe();

    const req = http.expectOne((r) => r.url.endsWith('/recipes'));
    expect(req.request.params.getAll('tags[]')).toEqual(['pasta', 'bystro']);
  });

  it('перед первым изменяющим запросом берёт cookie защиты от подделки', () => {
    api.registerForSession('nozh-i-doska').subscribe();

    // Сначала CSRF, и только потом само действие — иначе сервер ответит 419.
    http.expectOne(`${BASE}/sanctum/csrf-cookie`).flush('');
    const req = http.expectOne(`${BASE}/api/v1/academy/nozh-i-doska/registration`);
    expect(req.request.method).toBe('PUT');
    req.flush({ data: {} });
  });

  it('повторно cookie защиты не запрашивает', () => {
    api.registerForSession('a').subscribe();
    http.expectOne(`${BASE}/sanctum/csrf-cookie`).flush('');
    http.expectOne(`${BASE}/api/v1/academy/a/registration`).flush({ data: {} });

    api.registerForSession('b').subscribe();

    http.expectNone(`${BASE}/sanctum/csrf-cookie`);
    http.expectOne(`${BASE}/api/v1/academy/b/registration`).flush({ data: {} });
  });

  it('на 419 берёт токен заново и повторяет запрос один раз', () => {
    let result: unknown;
    api.registerForSession('a').subscribe((r) => (result = r));

    http.expectOne(`${BASE}/sanctum/csrf-cookie`).flush('');
    http.expectOne(`${BASE}/api/v1/academy/a/registration`).flush({ message: 'CSRF token mismatch.' }, { status: 419, statusText: 'Page Expired' });

    // Второй раз — свежий токен и тот же запрос.
    http.expectOne(`${BASE}/sanctum/csrf-cookie`).flush('');
    http.expectOne(`${BASE}/api/v1/academy/a/registration`).flush({ data: { ok: true } });

    expect(result).toEqual({ data: { ok: true } });
  });

  it('повторный 419 отдаётся наружу, без бесконечного цикла', () => {
    let status = 0;
    api.registerForSession('a').subscribe({ error: (e) => (status = e.status) });

    http.expectOne(`${BASE}/sanctum/csrf-cookie`).flush('');
    http.expectOne(`${BASE}/api/v1/academy/a/registration`).flush({}, { status: 419, statusText: 'Page Expired' });
    http.expectOne(`${BASE}/sanctum/csrf-cookie`).flush('');
    http.expectOne(`${BASE}/api/v1/academy/a/registration`).flush({}, { status: 419, statusText: 'Page Expired' });

    http.expectNone(`${BASE}/sanctum/csrf-cookie`);
    expect(status).toBe(419);
  });

  it('фильтр журнала по году и месяцу уходит числами', () => {
    api.journal({ year: 2026, month: 2, period: 'week' }).subscribe();

    const req = http.expectOne((r) => r.url.endsWith('/journal'));
    expect(req.request.params.get('year')).toBe('2026');
    expect(req.request.params.get('month')).toBe('2');
    expect(req.request.params.get('period')).toBe('week');
  });

  it('адрес скачивания выпуска строится без запроса', () => {
    expect(api.journalDownloadUrl('gayd-yanvar'))
      .toBe(`${BASE}/api/v1/journal/gayd-yanvar/download`);

    http.expectNone(() => true);
  });

  it('работа на конкурс уходит как форма с файлами', () => {
    const file = new File(['x'], 'photo.png', { type: 'image/png' });

    api.submitEntry('luchshee-foto', [file], 'Шакшука', 'Готовил в воскресенье').subscribe();

    http.expectOne(`${BASE}/sanctum/csrf-cookie`).flush('');
    const req = http.expectOne(`${BASE}/api/v1/contests/luchshee-foto/entries`);

    const body = req.request.body as FormData;
    expect(body instanceof FormData).toBeTrue();
    expect(body.getAll('files[]').length).toBe(1);
    expect(body.get('title')).toBe('Шакшука');
    req.flush({ data: {} });
  });

  it('голос отправляется к конкретной работе', () => {
    api.voteForEntry('luchshee-foto', 42).subscribe();

    http.expectOne(`${BASE}/sanctum/csrf-cookie`).flush('');
    const req = http.expectOne(`${BASE}/api/v1/contests/luchshee-foto/entries/42/vote`);
    expect(req.request.method).toBe('POST');
    req.flush({ data: { entry_id: 42, votes_count: 1 } });
  });

  it('таблица лидеров без уровня не шлёт пустой параметр', () => {
    api.leaderboard(undefined, 2).subscribe();

    const req = http.expectOne((r) => r.url.endsWith('/rating'));
    expect(req.request.params.has('level')).toBeFalse();
    expect(req.request.params.get('page')).toBe('2');
  });
});
