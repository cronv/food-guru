import { Component, OnInit, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';

import { CommerceService } from '@shared/services/commerce.service';
import { Product, rub } from '@shared/models/commerce.models';
import { errorText, plural } from '@shared/utils';
import { ToastService } from '../services/toast.service';

type AdminProduct = Product & { stock: number; is_published: boolean; cover_media_id: number | null };

@Component({
    selector: 'adm-products',
    imports: [RouterLink, FormsModule],
    template: `
    <header class="head">
      <div>
        <h1>Магазин</h1>
        <p class="muted">{{ total() }} {{ plural(total(), 'товар', 'товара', 'товаров') }}</p>
      </div>
      <a class="btn btn-green" routerLink="/products/new">Новый товар</a>
    </header>

    <div class="filters">
      <input type="search" [(ngModel)]="q" name="q" (keyup.enter)="load()"
             placeholder="Название или артикул" aria-label="Поиск товаров">
      <button class="btn btn-ghost btn-sm" type="button" (click)="load()">Найти</button>
    </div>

    @if (loading() && !rows().length) {
      <div class="loading"><div class="spinner"></div></div>
    } @else if (rows().length) {
      <div class="scroll">
        <table>
          <thead>
            <tr>
              <th>Фото</th>
              <th>Товар</th>
              <th>Категория</th>
              <th class="num">Цена</th>
              <th class="num">Остаток</th>
              <th>Статус</th>
              <th class="right">Действия</th>
            </tr>
          </thead>
          <tbody>
            @for (p of rows(); track p.id) {
              <tr>
                <td>
                  @if (p.cover; as c) {
                    <img class="thumb" [src]="c.url" [alt]="c.alt || p.title" loading="lazy">
                  } @else {
                    <div class="thumb ph muted">—</div>
                  }
                </td>
                <td>
                  <a class="name" [routerLink]="['/products', p.id]">{{ p.title }}</a>
                  @if (p.sku) { <div class="muted small">Арт. {{ p.sku }}</div> }
                </td>
                <td class="muted">{{ p.category || '—' }}</td>
                <td class="num tnum">
                  {{ rub(p.price) }}
                  @if (p.old_price) { <div class="muted small old">{{ rub(p.old_price) }}</div> }
                </td>
                <td class="num tnum" [class.out]="p.stock <= 0">{{ p.stock }}</td>
                <td>
                  <span class="chip" [class.chip-g]="p.is_published">
                    {{ p.is_published ? 'В продаже' : 'Скрыт' }}
                  </span>
                </td>
                <td class="right">
                  <a class="btn btn-ghost btn-sm" [routerLink]="['/products', p.id]">Открыть</a>
                </td>
              </tr>
            }
          </tbody>
        </table>
      </div>
    } @else {
      <div class="empty">
        <h3>Товаров пока нет</h3>
        <p class="muted">Поменяйте поиск или добавьте товар.</p>
        <a class="btn btn-green" routerLink="/products/new">Новый товар</a>
      </div>
    }
  `,
    styles: [`
    .head { display: flex; flex-wrap: wrap; gap: 16px; align-items: center; justify-content: space-between; margin-bottom: 18px; }
    h1 { font-size: 30px; }
    .head p { margin: 4px 0 0; font-size: 14px; }
    .filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 16px; }
    .filters input { flex: 1 1 240px; min-width: 0; }
    .thumb { width: 52px; height: 52px; object-fit: cover; border-radius: 8px; background: var(--sunken); display: block; }
    .ph { display: grid; place-items: center; border: 1px solid var(--line); font-size: 12px; }
    .name { font-weight: 600; }
    .small { font-size: 12px; }
    .old { text-decoration: line-through; }
    .out { color: var(--brick-ink); font-weight: 700; }
    .num { text-align: right; }
    .right { text-align: right; }
    .loading { display: grid; place-items: center; min-height: 30vh; }
    .btn-sm { min-height: 44px; }
  `]
})
export class ProductsPage implements OnInit {
  /** Номер запроса: ответ, отставший от нового поиска, игнорируем. */
  private loadSeq = 0;

  private shop = inject(CommerceService);
  private toasts = inject(ToastService);

  q = '';

  readonly rows = signal<AdminProduct[]>([]);
  readonly total = signal(0);
  readonly loading = signal(true);

  readonly rub = rub;
  readonly plural = plural;

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    const seq = ++this.loadSeq;

    this.shop.adminProducts(this.q.trim()).subscribe({
      next: (res) => {
        if (seq !== this.loadSeq) return;
        this.rows.set(res.data);
        this.total.set(res.meta?.total ?? res.data.length);
        this.loading.set(false);
      },
      error: (err) => {
        if (seq !== this.loadSeq) return;
        this.loading.set(false);
        this.toasts.error(errorText(err));
      },
    });
  }
}
