import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Router, provideRouter } from '@angular/router';

import { Product, insufficientFunds, rub } from '@shared/models/commerce.models';
import { User } from '@shared/models/api.models';
import { AuthService } from '@shared/services/auth.service';
import { API_BASE_URL, SITE_URL } from '@shared/tokens';
import { CartService } from '../services/cart.service';
import { CartPage } from './cart.page';
import { PaymentPage } from './payment.page';
import { ActivatedRoute, convertToParamMap } from '@angular/router';

const BASE = 'http://localhost:8000';
const providers = [
  provideRouter([]),
  provideHttpClient(),
  provideHttpClientTesting(),
  { provide: API_BASE_URL, useValue: BASE },
  { provide: SITE_URL, useValue: 'http://localhost:4200' },
];

const product = (id: number, price: number): Product =>
  ({ id, slug: `p${id}`, title: `Товар ${id}`, category: null, lead: null, price, old_price: null, in_stock: true, stock_left: null, cover: null });

describe('rub', () => {
  it('рубли без копеек и с копейками', () => {
    expect(rub(99000).replace(/\s/g, ' ')).toBe('990 ₽');
    expect(rub(123450).replace(/\s/g, ' ')).toBe('1 234,50 ₽');
  });
});

describe('insufficientFunds', () => {
  it('узнаёт только 402 с кодом insufficient_funds', () => {
    expect(insufficientFunds({ status: 402, error: { code: 'insufficient_funds', shortfall: 100 } })?.shortfall).toBe(100);
    expect(insufficientFunds({ status: 422, error: {} })).toBeNull();
  });
});

describe('CartService', () => {
  beforeEach(() => {
    localStorage.removeItem('fg-cart-v1');
    TestBed.configureTestingModule({ providers });
  });

  it('складывает одинаковые товары и считает сумму', () => {
    const cart = TestBed.inject(CartService);
    cart.add(product(1, 10000));
    cart.add(product(1, 10000), 2);
    cart.add(product(2, 5000));

    expect(cart.count()).toBe(4);
    expect(cart.total()).toBe(35000);
    expect(cart.quantityOf(1)).toBe(3);
  });

  it('ноль — значит убрать; переживает перезагрузку через localStorage', () => {
    const cart = TestBed.inject(CartService);
    cart.add(product(1, 10000));
    cart.add(product(2, 5000));
    cart.setQuantity(1, 0);

    expect(JSON.parse(localStorage.getItem('fg-cart-v1')!).map((l: { product_id: number }) => l.product_id)).toEqual([2]);
  });
});

describe('CartPage', () => {
  let http: HttpTestingController;

  async function create() {
    localStorage.removeItem('fg-cart-v1');
    await TestBed.configureTestingModule({ imports: [CartPage], providers }).compileComponents();
    TestBed.inject(AuthService).setUser({ id: 1, name: 'Анна', email_verified: true } as User);
    http = TestBed.inject(HttpTestingController);
    const cart = TestBed.inject(CartService);
    cart.add(product(7, 350000), 2);
    const f = TestBed.createComponent(CartPage);
    f.detectChanges();
    http.expectOne(`${BASE}/api/v1/shop/delivery`).flush({ data: [
      { code: 'pickup', label: 'Самовывоз', price: 0, needs_address: false },
      { code: 'courier', label: 'Курьер', price: 39000, needs_address: true },
    ] });
    return { f, cart };
  }

  it('курьеру без адреса заказ не отправляется', async () => {
    const { f } = await create();
    const page = f.componentInstance;
    page.method = 'courier';
    page.phone = '+7 900 123-45-67';
    page.submit(new Event('submit'));

    expect(page.errors()).toContain('Укажите адрес доставки.');
    http.expectNone(`${BASE}/api/v1/shop/orders`);
  });

  it('отправляет только id и количество, очищает корзину после заказа', async () => {
    const { f, cart } = await create();
    const page = f.componentInstance;
    const router = TestBed.inject(Router);
    spyOn(router, 'navigate').and.resolveTo(true);

    page.phone = '+7 900 123-45-67';
    page.submit(new Event('submit'));

    http.expectOne(`${BASE}/sanctum/csrf-cookie`).flush('');
    const req = http.expectOne(`${BASE}/api/v1/shop/orders`);
    expect(req.request.body.items).toEqual([{ product_id: 7, quantity: 2 }]);
    expect(req.request.body.recipient_name).toBe('Анна');
    req.flush({ data: { number: 'FG-1' } });

    expect(cart.count()).toBe(0);
    expect(router.navigate).toHaveBeenCalledWith(['/orders'], { queryParams: { placed: 'FG-1' } });
  });

  it('при нехватке денег показывает, сколько не хватает, и корзину не трогает', async () => {
    const { f, cart } = await create();
    const page = f.componentInstance;
    page.phone = '+7 900 123-45-67';
    page.submit(new Event('submit'));

    http.expectOne(`${BASE}/sanctum/csrf-cookie`).flush('');
    http.expectOne(`${BASE}/api/v1/shop/orders`).flush(
      { code: 'insufficient_funds', message: 'Нет', balance: 100000, needed: 700000, shortfall: 600000 },
      { status: 402, statusText: 'Payment Required' },
    );

    expect(page.funds()?.shortfall).toBe(600000);
    expect(cart.count()).toBe(2);
  });
});

describe('PaymentPage', () => {
  it('возврат после оплаты — только на свои страницы', async () => {
    await TestBed.configureTestingModule({
      imports: [PaymentPage],
      providers: [...providers, {
        provide: ActivatedRoute,
        useValue: { snapshot: { paramMap: convertToParamMap({ id: 'abc' }), queryParamMap: convertToParamMap({ back: '//evil.example' }) } },
      }],
    }).compileComponents();
    TestBed.inject(AuthService).setUser({ id: 1 } as User);
    const http = TestBed.inject(HttpTestingController);
    const f = TestBed.createComponent(PaymentPage);
    f.detectChanges();
    http.expectOne(`${BASE}/api/v1/payments/abc`).flush({ data: { id: 'abc', status: 'pending', provider: 'test', amount: 100000 } });

    expect(f.componentInstance.back()).toBeNull();
  });
});
