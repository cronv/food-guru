import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';

import { Order } from '@shared/models/commerce.models';
import { API_BASE_URL, SITE_URL } from '@shared/tokens';
import { OrdersPage } from './orders.page';

describe('OrdersPage', () => {
  const BASE = 'http://localhost:8000';
  let fixture: ComponentFixture<OrdersPage>;
  let page: OrdersPage;
  let http: HttpTestingController;

  const order = (over: Partial<Order> = {}): Order => ({
    id: 7, number: 'FG-0007', status: 'paid', status_label: 'Оплачен', can_cancel: true,
    next_statuses: ['assembling', 'canceled'],
    items_total: 99000, delivery_method: 'courier', delivery_label: 'Курьер', delivery_price: 0, total: 99000,
    recipient_name: 'Анна', phone: '+79990000000', city: 'Москва', address: 'Тверская, 1', comment: null,
    items: [{ product_id: 1, title: 'Сковорода', price: 99000, quantity: 1, total: 99000 }],
    created_at: null, shipped_at: null, delivered_at: null, canceled_at: null,
    user: { id: 3, name: 'Анна', email: 'anna@example.com' },
    ...over,
  });

  const list = (rows: Order[]) => ({
    data: rows,
    meta: { current_page: 1, last_page: 1, total: rows.length, statuses: [] },
  });

  const el = (): HTMLElement => fixture.nativeElement as HTMLElement;
  const button = (text: string): HTMLButtonElement | undefined =>
    Array.from(el().querySelectorAll('button')).find((b) => b.textContent?.trim() === text);

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [OrdersPage],
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: BASE },
        { provide: SITE_URL, useValue: 'http://localhost:4200' },
      ],
    }).compileComponents();

    http = TestBed.inject(HttpTestingController);
    fixture = TestBed.createComponent(OrdersPage);
    page = fixture.componentInstance;
    fixture.detectChanges();

    http.expectOne(`${BASE}/api/v1/admin/orders?status=active&page=1`).flush(list([order()]));
    fixture.detectChanges();
  });

  afterEach(() => http.verify());

  it('показывает заказ и кнопки допустимых переходов', () => {
    expect(el().textContent).toContain('FG-0007');
    expect(button('Начать сборку')).toBeTruthy();
    expect(button('Отменить заказ')).toBeTruthy();
    expect(button('Отправлен')).toBeUndefined();
  });

  it('переход статуса уходит PATCH-ом и обновляет строку', () => {
    button('Начать сборку')!.click();

    http.expectOne(`${BASE}/sanctum/csrf-cookie`).flush('');
    const req = http.expectOne(`${BASE}/api/v1/admin/orders/7`);
    expect(req.request.method).toBe('PATCH');
    expect(req.request.body).toEqual({ status: 'assembling' });
    req.flush({ data: order({ status: 'assembling', status_label: 'Собирается', next_statuses: ['shipped', 'canceled'] }) });
    fixture.detectChanges();

    expect(page.rows()[0].status).toBe('assembling');
    expect(button('Отправлен')).toBeTruthy();
    expect(button('Начать сборку')).toBeUndefined();
  });

  it('отмена сначала спрашивает подтверждение, «Нет» ничего не отправляет', () => {
    button('Отменить заказ')!.click();
    fixture.detectChanges();

    http.expectNone(() => true);
    expect(el().textContent).toContain('Отменить заказ? Деньги вернутся на баланс покупателя');

    button('Нет')!.click();
    fixture.detectChanges();

    http.expectNone(() => true);
    expect(page.confirmCancel()).toBeNull();
    expect(el().textContent).not.toContain('Деньги вернутся на баланс покупателя');
  });

  it('подтверждённая отмена отправляет статус canceled', () => {
    button('Отменить заказ')!.click();
    fixture.detectChanges();
    button('Да, отменить')!.click();

    http.expectOne(`${BASE}/sanctum/csrf-cookie`).flush('');
    const req = http.expectOne(`${BASE}/api/v1/admin/orders/7`);
    expect(req.request.body).toEqual({ status: 'canceled' });
    req.flush({ data: order({ status: 'canceled', status_label: 'Отменён', next_statuses: [], can_cancel: false }) });
    fixture.detectChanges();

    expect(page.rows()[0].status).toBe('canceled');
    expect(page.confirmCancel()).toBeNull();
    expect(button('Отменить заказ')).toBeUndefined();
  });

  it('фильтр по статусу перезагружает список', () => {
    page.setStatus('shipped');

    http.expectOne(`${BASE}/api/v1/admin/orders?status=shipped&page=1`).flush(list([]));
    fixture.detectChanges();

    expect(el().textContent).toContain('Заказов нет');
  });
});
