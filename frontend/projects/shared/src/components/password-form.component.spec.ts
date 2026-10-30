import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';

import { API_BASE_URL } from '../tokens';
import { PasswordFormComponent } from './password-form.component';

/**
 * Правила пароля проверяет сервер. Здесь — только то, что форма не отправит
 * заведомо неверное: несовпавшие пароли и смену без текущего.
 */
describe('PasswordFormComponent', () => {
  const BASE = 'http://localhost:8000';
  let fixture: ComponentFixture<PasswordFormComponent>;
  let cmp: PasswordFormComponent;
  let http: HttpTestingController;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [PasswordFormComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: BASE },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(PasswordFormComponent);
    cmp = fixture.componentInstance;
    http = TestBed.inject(HttpTestingController);
    fixture.detectChanges();
  });

  afterEach(() => http.verify());

  const submit = () => cmp.submit(new Event('submit'));

  it('несовпавшие пароли на сервер не уходят', () => {
    cmp.password = 'Borsch2026';
    cmp.repeat = 'Borsch2027';
    submit();

    expect(cmp.error()).toBe('Пароли не совпадают.');
    http.expectNone(() => true);
  });

  it('первый пароль уходит без текущего', () => {
    cmp.password = cmp.repeat = 'Borsch2026';
    submit();

    http.expectOne(`${BASE}/sanctum/csrf-cookie`).flush('');
    const req = http.expectOne(`${BASE}/api/v1/me/password`);
    expect(req.request.method).toBe('PUT');
    expect(req.request.body).toEqual({ password: 'Borsch2026', password_confirmation: 'Borsch2026' });
    req.flush({ data: {} });
  });

  it('смена пароля без текущего не отправляется', () => {
    fixture.componentRef.setInput('requireCurrent', true);
    fixture.detectChanges();

    cmp.password = cmp.repeat = 'Borsch2026';
    submit();

    expect(cmp.error()).toBe('Введите текущий пароль.');
    http.expectNone(() => true);
  });

  it('смена пароля несёт текущий', () => {
    fixture.componentRef.setInput('requireCurrent', true);
    fixture.detectChanges();

    cmp.current = 'Old12345pw';
    cmp.password = cmp.repeat = 'Borsch2026';
    submit();

    http.expectOne(`${BASE}/sanctum/csrf-cookie`).flush('');
    const req = http.expectOne(`${BASE}/api/v1/me/password`);
    expect(req.request.body.current_password).toBe('Old12345pw');
    req.flush({ data: {} });
  });

  it('ошибка сервера показывается рядом с формой', () => {
    cmp.password = cmp.repeat = 'short';
    submit();

    http.expectOne(`${BASE}/sanctum/csrf-cookie`).flush('');
    http.expectOne(`${BASE}/api/v1/me/password`).flush(
      { message: 'x', errors: { password: ['Пароль должен быть не короче 8 символов.'] } },
      { status: 422, statusText: 'Unprocessable' },
    );

    expect(cmp.error()).toBe('Пароль должен быть не короче 8 символов.');
    expect(cmp.saving()).toBeFalse();
  });
});
