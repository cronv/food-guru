import { ComponentFixture, TestBed } from '@angular/core/testing';
import { ActivatedRoute, provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { BehaviorSubject } from 'rxjs';

import { API_BASE_URL, SITE_URL } from '@shared/tokens';
import { JournalPage } from './journal.page';

/**
 * Главное, что здесь проверяется, — защита от гонки ответов.
 *
 * Запросы не отменяются, поэтому при быстрой смене отбора ответы приходят
 * вперемешку. Без номера запроса поздний старый ответ затирает свежий список,
 * а ответ второй страницы дописывается к уже обновлённому первому экрану.
 */
describe('JournalPage', () => {
  let fixture: ComponentFixture<JournalPage>;
  let page: JournalPage;
  let http: HttpTestingController;
  let queryParams: BehaviorSubject<Record<string, string>>;

  const BASE = '';

  const issue = (slug: string) => ({
    id: 1, slug, title: slug, lead: null,
    period: 'month' as const, period_label: 'Гайд месяца', period_range: 'Январь 2026',
    issue_date: '2026-01-01', issue_year: 2026, issue_month: 1,
    status: 'published' as const, pages: 10, file_size: 1024,
    has_file: true, downloads_count: 0, published_at: '2026-01-02T00:00:00+00:00',
  });

  const payload = (slugs: string[], currentPage = 1, lastPage = 2) => ({
    data: slugs.map(issue),
    meta: { current_page: currentPage, last_page: lastPage, per_page: 12, total: 24 },
  });

  /** Берёт запрос к списку, пропуская справочник периодов. */
  const listRequests = () => http.match((r) => r.url.endsWith('/api/v1/journal'));

  beforeEach(async () => {
    queryParams = new BehaviorSubject<Record<string, string>>({});

    await TestBed.configureTestingModule({
      imports: [JournalPage],
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: BASE },
        { provide: SITE_URL, useValue: 'http://localhost:4200' },
        { provide: ActivatedRoute, useValue: { queryParams: queryParams.asObservable() } },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(JournalPage);
    page = fixture.componentInstance;
    http = TestBed.inject(HttpTestingController);

    fixture.detectChanges();

    // Справочник годов запрашивается один раз и к отбору отношения не имеет.
    http.expectOne((r) => r.url.endsWith('/api/v1/journal/periods'))
      .flush({ data: [{ year: 2026, count: 2, months: [{ month: 1, label: 'Январь', count: 2 }] }], meta: { total: 2 } });
  });

  afterEach(() => http.verify());

  it('показывает выпуски из ответа', () => {
    listRequests()[0].flush(payload(['yanvar']));

    expect(page.items().length).toBe(1);
    expect(page.items()[0].slug).toBe('yanvar');
    expect(page.loading()).toBeFalse();
  });

  it('отставший ответ не затирает свежий список', () => {
    const first = listRequests()[0];

    // Человек меняет отбор, не дождавшись первого ответа.
    queryParams.next({ year: '2026' });
    const second = listRequests().at(-1)!;

    // Ответы приходят в обратном порядке.
    second.flush(payload(['svezhiy']));
    first.flush(payload(['ustarevshiy']));

    expect(page.items().map((i) => i.slug)).toEqual(['svezhiy']);
  });

  it('ошибка отставшего запроса не роняет страницу в состояние сбоя', () => {
    const first = listRequests()[0];

    queryParams.next({ period: 'week' });
    const second = listRequests().at(-1)!;

    second.flush(payload(['svezhiy']));
    first.error(new ProgressEvent('error'), { status: 500, statusText: 'Server Error' });

    expect(page.failed()).toBeFalse();
    expect(page.items().length).toBe(1);
  });

  it('справочник периодов запрашивается один раз, а не на каждый отбор', () => {
    listRequests()[0].flush(payload(['a']));

    queryParams.next({ year: '2026' });
    listRequests().at(-1)!.flush(payload(['b']));

    http.expectNone((r) => r.url.endsWith('/api/v1/journal/periods'));
  });

  it('отбор из адреса уходит в запрос', () => {
    listRequests()[0].flush(payload(['a']));

    queryParams.next({ year: '2026', month: '1', period: 'month', q: 'суп' });
    const req = listRequests().at(-1)!;

    expect(req.request.params.get('year')).toBe('2026');
    expect(req.request.params.get('month')).toBe('1');
    expect(req.request.params.get('period')).toBe('month');
    expect(req.request.params.get('q')).toBe('суп');

    req.flush(payload(['a']));
  });
});
