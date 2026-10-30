import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { Envelope, Paginated } from '../models/api.models';
import {
  BookCard, BookChapterText, BookDetail, BookFacets, BookFilters, ClubDetail, ClubIndex, DeliveryMethod, Order,
  OrderRequest, OrderStatus, PaymentInfo, PaymentMethods, Product, ProductFacets, ProductFilters, SubscriptionState,
  WalletState,
} from '../models/commerce.models';
import { ApiService } from './api.service';

type Meta = { current_page: number; last_page: number; total: number };

/** Кошелёк, книги, магазин, закрытая академия — сайт и админка. */
@Injectable({ providedIn: 'root' })
export class CommerceService {
  private http = inject(HttpClient);
  private api = inject(ApiService);

  private get<T>(path: string, params: Record<string, unknown> = {}): Observable<T> {
    return this.http.get<T>(this.api.url(path), { params: this.api.params(params), withCredentials: true });
  }

  private send<T>(method: 'post' | 'patch' | 'put' | 'delete', path: string, body: unknown = {}): Observable<T> {
    const url = this.api.url(path);
    return this.api.withCsrf(() => method === 'delete'
      ? this.http.delete<T>(url, { withCredentials: true })
      : this.http.request<T>(method.toUpperCase(), url, { body, withCredentials: true }));
  }

  /* ---------- кошелёк ---------- */

  wallet(page = 1): Observable<{ data: WalletState; meta: Meta }> {
    return this.get('me/wallet', { page });
  }

  paymentMethods(): Observable<Envelope<PaymentMethods>> {
    return this.get('payments/methods');
  }

  topUp(amount: number, method: string): Observable<Envelope<PaymentInfo>> {
    return this.send('post', 'me/wallet/topups', { amount, method });
  }

  payment(id: string): Observable<Envelope<PaymentInfo>> {
    return this.get(`payments/${id}`);
  }

  /** Только для тестовой платёжной системы. */
  testComplete(id: string, result: 'succeeded' | 'canceled'): Observable<Envelope<PaymentInfo>> {
    return this.send('post', `payments/${id}/test-complete`, { result });
  }

  /* ---------- книги ---------- */

  /** Строка — прежний вызов (только статус), объект — полный набор фильтров. */
  books(filters: BookFilters | BookFilters['status'] = 'all'): Observable<{ data: BookCard[]; meta: { total: number; facets: BookFacets } }> {
    const f = typeof filters === 'string' ? { status: filters } : filters;
    return this.get('books', f as Record<string, unknown>);
  }

  book(slug: string): Observable<Envelope<BookDetail>> {
    return this.get(`books/${slug}`);
  }

  chapter(slug: string, position: number): Observable<Envelope<BookChapterText>> {
    return this.get(`books/${slug}/chapters/${position}`);
  }

  buyBook(slug: string): Observable<Envelope<BookCard>> {
    return this.send('post', `books/${slug}/purchase`);
  }

  /** Адрес PDF: браузер скачивает его сам, с cookie сессии. */
  bookPdfUrl(slug: string): string {
    return this.api.url(`books/${slug}/pdf`);
  }

  /* ---------- магазин ---------- */

  products(filters: ProductFilters = {}): Observable<{ data: Product[]; meta: { total: number; facets: ProductFacets } }> {
    return this.get('shop/products', filters as Record<string, unknown>);
  }

  product(slug: string): Observable<Envelope<Product>> {
    return this.get(`shop/products/${slug}`);
  }

  delivery(): Observable<{ data: DeliveryMethod[] }> {
    return this.get('shop/delivery');
  }

  placeOrder(order: OrderRequest): Observable<Envelope<Order>> {
    return this.send('post', 'shop/orders', order);
  }

  myOrders(page = 1): Observable<{ data: Order[]; meta: Meta }> {
    return this.get('me/orders', { page });
  }

  cancelOrder(number: string): Observable<Envelope<Order>> {
    return this.send('post', `me/orders/${number}/cancel`);
  }

  /* ---------- закрытая академия ---------- */

  club(kind?: string): Observable<ClubIndex> {
    return this.get('club', { kind });
  }

  clubItem(slug: string): Observable<{ data: ClubDetail; meta: { subscription: SubscriptionState } }> {
    return this.get(`club/${slug}`);
  }

  subscribe(plan: string): Observable<Envelope<SubscriptionState>> {
    return this.send('post', 'club/subscribe', { plan });
  }

  /* ---------- админка ---------- */

  adminProducts(q = ''): Observable<{ data: Array<Product & { stock: number; is_published: boolean; cover_media_id: number | null }>; meta: Meta }> {
    return this.get('admin/products', { q });
  }

  adminProduct(id: number): Observable<Envelope<Product & { stock: number; is_published: boolean; cover_media_id: number | null }>> {
    return this.get(`admin/products/${id}`);
  }

  saveProduct(data: Record<string, unknown>, id?: number): Observable<Envelope<Product & { id: number }>> {
    return id ? this.send('patch', `admin/products/${id}`, data) : this.send('post', 'admin/products', data);
  }

  adminOrders(status = 'active', page = 1): Observable<Paginated<Order> & { meta: { statuses: Array<{ code: OrderStatus; label: string }> } }> {
    return this.get('admin/orders', { status, page });
  }

  /** id — числовой, приходит в админском списке заказов. */
  updateOrder(id: number, status: OrderStatus): Observable<Envelope<Order>> {
    return this.send('patch', `admin/orders/${id}`, { status });
  }

  adminBooks(): Observable<{ data: AdminBook[] }> {
    return this.get('admin/books');
  }

  adminBook(id: number): Observable<Envelope<AdminBook & { description: string | null; chapters: Array<{ title: string; body: string; is_preview: boolean }> }>> {
    return this.get(`admin/books/${id}`);
  }

  saveBook(data: Record<string, unknown>, id?: number): Observable<Envelope<AdminBook>> {
    return id ? this.send('patch', `admin/books/${id}`, data) : this.send('post', 'admin/books', data);
  }

  adminClub(): Observable<{ data: AdminClubItem[] }> {
    return this.get('admin/club');
  }

  adminClubItem(id: number): Observable<Envelope<AdminClubItem & { body: string | null; data: { ingredients?: string[]; steps?: string[] } | null }>> {
    return this.get(`admin/club/${id}`);
  }

  saveClubItem(data: Record<string, unknown>, id?: number): Observable<Envelope<AdminClubItem>> {
    return id ? this.send('patch', `admin/club/${id}`, data) : this.send('post', 'admin/club', data);
  }

  uploadClubVideo(id: number, file: File): Observable<Envelope<AdminClubItem>> {
    const form = new FormData();
    form.append('file', file);
    return this.send('post', `admin/club/${id}/video`, form);
  }

  userWallet(userId: number): Observable<Envelope<WalletState>> {
    return this.get(`admin/users/${userId}/wallet`);
  }

  adjustWallet(userId: number, amount: number, reason: string): Observable<Envelope<{ balance: number }>> {
    return this.send('post', `admin/users/${userId}/wallet`, { amount, reason });
  }
}

export interface AdminBook {
  id: number;
  slug: string;
  title: string;
  author_name: string | null;
  year: number | null;
  country: string | null;
  lead: string | null;
  price: number;
  in_subscription: boolean;
  is_published: boolean;
  cover_media_id: number | null;
  cover: { url: string; alt: string | null } | null;
  chapters_count: number;
  purchases_count: number;
}

export interface AdminClubItem {
  id: number;
  slug: string;
  kind: 'video' | 'masterclass' | 'recipe' | 'material';
  kind_label: string;
  title: string;
  lead: string | null;
  chef_name: string | null;
  duration_minutes: number | null;
  has_video: boolean;
  is_published: boolean;
  cover_media_id: number | null;
  cover: { url: string; alt: string | null } | null;
}
