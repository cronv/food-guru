import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';

import { CookbookNote, User } from '@shared/models/api.models';
import { AuthService } from '@shared/services/auth.service';
import { API_BASE_URL, SITE_URL } from '@shared/tokens';
import { CookbookPage } from './cookbook.page';

describe('CookbookPage', () => {
  const BASE = 'http://localhost:8000';
  let fixture: ComponentFixture<CookbookPage>;
  let page: CookbookPage;
  let http: HttpTestingController;

  const note = (id: number, title: string): CookbookNote =>
    ({ id, title, url: null, body: 'текст', created_at: null, updated_at: null });

  const empty = { data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } };

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [CookbookPage],
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: BASE },
        { provide: SITE_URL, useValue: 'http://localhost:4200' },
      ],
    }).compileComponents();

    TestBed.inject(AuthService).setUser({ id: 1, email_verified: true } as User);
    http = TestBed.inject(HttpTestingController);
    fixture = TestBed.createComponent(CookbookPage);
    page = fixture.componentInstance;
    fixture.detectChanges();

    http.expectOne((r) => r.url === `${BASE}/api/v1/me/cookbook/recipes`).flush(empty);
    http.expectOne(`${BASE}/api/v1/me/cookbook/notes?page=1`).flush({ ...empty, data: [note(1, 'Старая')], meta: { ...empty.meta, total: 1 } });
  });

  afterEach(() => http.verify());

  const submit = () => page.saveNote(new Event('submit'));

  it('без ссылки и без текста заметка не отправляется', () => {
    page.title = 'Пирог';
    submit();

    expect(page.formError()).toContain('ссылку');
    http.expectNone(() => true);
  });

  it('новая заметка встаёт первой и увеличивает счётчик', () => {
    page.title = '  Борщ  ';
    page.url = 'https://example.com/borsch';
    submit();

    http.expectOne(`${BASE}/sanctum/csrf-cookie`).flush('');
    const req = http.expectOne(`${BASE}/api/v1/me/cookbook/notes`);
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ title: 'Борщ', url: 'https://example.com/borsch', body: null });
    req.flush({ data: note(2, 'Борщ') });

    expect(page.notes().map((n) => n.title)).toEqual(['Борщ', 'Старая']);
    expect(page.notesTotal()).toBe(2);
    expect(page.title).toBe('');
  });

  it('удаление убирает заметку из списка', () => {
    page.remove(note(1, 'Старая'));

    http.expectOne(`${BASE}/sanctum/csrf-cookie`).flush('');
    http.expectOne(`${BASE}/api/v1/me/cookbook/notes/1`).flush({});

    expect(page.notes().length).toBe(0);
    expect(page.notesTotal()).toBe(0);
  });

  it('домен ссылки показывается без www', () => {
    expect(page.host('https://www.example.com/a/b')).toBe('example.com');
  });
});
