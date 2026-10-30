import { Component, OnInit, inject, signal } from '@angular/core';

import { CommerceService } from '@shared/services/commerce.service';
import { Order, OrderStatus, rub } from '@shared/models/commerce.models';
import { errorText, formatShortDate, plural } from '@shared/utils';
import { ToastService } from '../services/toast.service';

type OrderFilter = 'active' | 'all' | OrderStatus;

const FILTERS: { value: OrderFilter; label: string }[] = [
  { value: 'active', label: 'В работе' },
  { value: 'paid', label: 'Оплачены' },
  { value: 'assembling', label: 'Собираются' },
  { value: 'shipped', label: 'Отправлены' },
  { value: 'delivered', label: 'Доставлены' },
  { value: 'canceled', label: 'Отменены' },
  { value: 'all', label: 'Все' },
];

/** Подписи кнопок перехода: что сделать с заказом, а не его будущий статус. */
const MOVE_LABELS: Record<OrderStatus, string> = {
  paid: 'Вернуть в «Оплачен»',
  assembling: 'Начать сборку',
  shipped: 'Отправлен',
  delivered: 'Доставлен',
  canceled: 'Отменить заказ',
};

@Component({
  selector: 'adm-orders',
  standalone: true,
  template: `
    <header class="head">
      <h1>Заказы</h1>
      <p class="muted">{{ total() }} {{ plural(total(), 'заказ', 'заказа', 'заказов') }} в выбранном разделе</p>
    </header>

    <div class="filters" role="group" aria-label="Статус заказов">
      @for (f of filters; track f.value) {
        <button type="button" class="btn btn-sm" [class.btn-green]="status() === f.value"
                [class.btn-ghost]="status() !== f.value" [attr.aria-pressed]="status() === f.value"
                (click)="setStatus(f.value)">{{ f.label }}</button>
      }
    </div>

    @if (loading() && !rows().length) {
      <div class="loading"><div class="spinner"></div></div>
    } @else if (rows().length) {
      <div class="list">
        @for (o of rows(); track o.number) {
          <article class="item">
            <div class="top">
              <strong class="number">№ {{ o.number }}</strong>
              <span class="chip" [class]="chipClass(o.status)">{{ o.status_label }}</span>
              <span class="muted when">{{ when(o.created_at) }}</span>
            </div>

            <ul class="items">
              @for (it of o.items; track $index) {
                <li>
                  <span class="it-title">{{ it.title }}</span>
                  <span class="muted tnum">{{ it.quantity }} × {{ rub(it.price) }}</span>
                  <span class="tnum">{{ rub(it.total) }}</span>
                </li>
              }
            </ul>

            <div class="sum">
              <span class="muted">{{ o.delivery_label }}@if (o.delivery_price) { · {{ rub(o.delivery_price) }} }</span>
              <strong class="tnum">Итого {{ rub(o.total) }}</strong>
            </div>

            <dl class="facts">
              <div><dt class="muted">Получатель</dt><dd>{{ o.recipient_name }} · <a [href]="'tel:' + o.phone">{{ o.phone }}</a></dd></div>
              @if (o.city || o.address) {
                <div><dt class="muted">Адрес</dt><dd>{{ addressOf(o) }}</dd></div>
              }
              @if (o.user; as u) {
                <div><dt class="muted">Покупатель</dt><dd>{{ u.name }} · {{ u.email }}</dd></div>
              }
              @if (o.comment) {
                <div><dt class="muted">Комментарий</dt><dd>{{ o.comment }}</dd></div>
              }
            </dl>

            @if (confirmCancel() === o.id) {
              <div class="confirm note note-warn" role="alert">
                <span>Отменить заказ? Деньги вернутся на баланс покупателя</span>
                <div class="actions">
                  <button class="btn btn-primary btn-sm" type="button" [disabled]="busy() === o.id"
                          (click)="move(o, 'canceled')">Да, отменить</button>
                  <button class="btn btn-ghost btn-sm" type="button"
                          (click)="confirmCancel.set(null)">Нет</button>
                </div>
              </div>
            } @else if (o.next_statuses.length) {
              <div class="actions">
                @for (s of o.next_statuses; track s) {
                  <button type="button" class="btn btn-sm"
                          [class.btn-green]="s !== 'canceled'" [class.btn-ghost]="s === 'canceled'"
                          [disabled]="busy() === o.id" (click)="ask(o, s)">{{ moveLabel(s) }}</button>
                }
              </div>
            }
          </article>
        }
      </div>

      @if (page() < lastPage()) {
        <div class="more">
          <button class="btn btn-ghost" type="button" [disabled]="loading()" (click)="load(page() + 1)">Показать ещё</button>
        </div>
      }
    } @else {
      <div class="empty"><h3>Заказов нет</h3><p class="muted">В этом разделе пока пусто.</p></div>
    }
  `,
  styles: [`
    .head { margin-bottom: 16px; }
    h1 { font-size: 30px; }
    .head p { margin: 4px 0 0; font-size: 14px; }
    .filters { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 18px; }
    .list { display: flex; flex-direction: column; gap: 12px; }
    .item {
      background: var(--surface); border: 1px solid var(--line); border-radius: 16px; padding: 16px 20px;
      display: flex; flex-direction: column; gap: 12px; min-width: 0;
    }
    .top { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
    .number { font-size: 16px; }
    .when { margin-left: auto; font-size: 12px; }
    .items { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
    .items li { display: flex; flex-wrap: wrap; gap: 4px 12px; font-size: 14px; }
    .it-title { flex: 1 1 200px; min-width: 0; overflow-wrap: anywhere; }
    .sum {
      display: flex; flex-wrap: wrap; gap: 6px 12px; justify-content: space-between;
      padding-top: 10px; border-top: 1px solid var(--line); font-size: 14px;
    }
    .facts { margin: 0; display: flex; flex-direction: column; gap: 6px; font-size: 14px; }
    .facts div { display: flex; flex-wrap: wrap; gap: 2px 10px; }
    .facts dt { flex: 0 0 110px; font-size: 13px; }
    .facts dd { margin: 0; flex: 1 1 200px; min-width: 0; overflow-wrap: anywhere; }
    .actions { display: flex; flex-wrap: wrap; gap: 8px; }
    .confirm { display: flex; flex-direction: column; gap: 10px; margin: 0; }
    .more { display: flex; justify-content: center; margin-top: 18px; }
    .loading { display: grid; place-items: center; min-height: 30vh; }
    .btn-sm { min-height: 44px; }
  `],
})
export class OrdersPage implements OnInit {
  /** Номер запроса: ответ, отставший от нового фильтра, игнорируем. */
  private loadSeq = 0;

  private shop = inject(CommerceService);
  private toasts = inject(ToastService);

  readonly filters = FILTERS;
  readonly status = signal<OrderFilter>('active');
  readonly rows = signal<Order[]>([]);
  readonly total = signal(0);
  readonly page = signal(1);
  readonly lastPage = signal(1);
  readonly loading = signal(true);
  readonly busy = signal<number | null>(null);
  /** id заказа, для которого открыт вопрос «Отменить заказ?». */
  readonly confirmCancel = signal<number | null>(null);

  readonly rub = rub;
  readonly plural = plural;

  addressOf(o: Order): string {
    return [o.city, o.address].filter(Boolean).join(', ');
  }

  ngOnInit(): void {
    this.load();
  }

  setStatus(status: OrderFilter): void {
    this.status.set(status);
    this.rows.set([]);
    this.confirmCancel.set(null);
    this.load();
  }

  load(page = 1): void {
    this.loading.set(true);
    const seq = ++this.loadSeq;

    this.shop.adminOrders(this.status(), page).subscribe({
      next: (res) => {
        if (seq !== this.loadSeq) return;
        this.rows.set(page === 1 ? res.data : [...this.rows(), ...res.data]);
        this.total.set(res.meta.total);
        this.page.set(res.meta.current_page);
        this.lastPage.set(res.meta.last_page);
        this.loading.set(false);
      },
      error: (err) => {
        if (seq !== this.loadSeq) return;
        this.loading.set(false);
        this.toasts.error(errorText(err));
      },
    });
  }

  moveLabel(s: OrderStatus): string {
    return MOVE_LABELS[s] ?? s;
  }

  chipClass(s: OrderStatus): string {
    if (s === 'delivered') return 'chip-g';
    if (s === 'paid' || s === 'canceled') return 'chip-b';
    return '';
  }

  /** Отмена возвращает деньги и остатки — её сначала подтверждают. */
  ask(order: Order, status: OrderStatus): void {
    if (status === 'canceled') {
      this.confirmCancel.set(order.id ?? null);
      return;
    }
    this.move(order, status);
  }

  move(order: Order, status: OrderStatus): void {
    const id = order.id;
    if (!id) return;

    this.confirmCancel.set(null);
    this.busy.set(id);
    this.shop.updateOrder(id, status).subscribe({
      next: (res) => {
        this.busy.set(null);
        // Ответ может прийти без админских полей — сохраняем их из строки списка.
        this.rows.update((list) => list.map((o) => (o.id === id ? { ...o, ...res.data, id, user: res.data.user ?? o.user } : o)));
        this.toasts.show(status === 'canceled'
          ? `Заказ № ${order.number} отменён, деньги вернулись на баланс`
          : `Заказ № ${order.number}: ${res.data.status_label}`);
      },
      error: (err) => {
        this.busy.set(null);
        this.toasts.error(errorText(err));
      },
    });
  }

  when(iso: string | null): string {
    return formatShortDate(iso);
  }
}
