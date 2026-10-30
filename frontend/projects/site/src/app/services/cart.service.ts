import { Injectable, PLATFORM_ID, computed, inject, signal } from '@angular/core';
import { isPlatformBrowser } from '@angular/common';

import { Product } from '@shared/models/commerce.models';

export interface CartLine {
  product_id: number;
  slug: string;
  title: string;
  /** Цена на момент добавления — только для показа. Заказ считает сервер по базе. */
  price: number;
  cover: string | null;
  quantity: number;
}

const KEY = 'fg-cart-v1';

/**
 * Корзина живёт в браузере: гостю не нужен аккаунт, чтобы что-то в неё положить.
 * Хранилище может быть недоступно (приватный режим) — тогда корзина просто
 * не переживёт перезагрузку, но работает.
 */
@Injectable({ providedIn: 'root' })
export class CartService {
  private browser = isPlatformBrowser(inject(PLATFORM_ID));

  readonly lines = signal<CartLine[]>(this.restore());
  readonly count = computed(() => this.lines().reduce((n, l) => n + l.quantity, 0));
  readonly total = computed(() => this.lines().reduce((s, l) => s + l.price * l.quantity, 0));

  quantityOf(productId: number): number {
    return this.lines().find((l) => l.product_id === productId)?.quantity ?? 0;
  }

  add(product: Product, quantity = 1): void {
    this.update((lines) => {
      const found = lines.find((l) => l.product_id === product.id);
      if (found) {
        return lines.map((l) => (l === found ? { ...l, quantity: Math.min(99, l.quantity + quantity) } : l));
      }
      return [...lines, {
        product_id: product.id,
        slug: product.slug,
        title: product.title,
        price: product.price,
        cover: product.cover?.url ?? null,
        quantity,
      }];
    });
  }

  setQuantity(productId: number, quantity: number): void {
    this.update((lines) => quantity <= 0
      ? lines.filter((l) => l.product_id !== productId)
      : lines.map((l) => (l.product_id === productId ? { ...l, quantity: Math.min(99, quantity) } : l)));
  }

  remove(productId: number): void {
    this.setQuantity(productId, 0);
  }

  clear(): void {
    this.update(() => []);
  }

  private update(fn: (lines: CartLine[]) => CartLine[]): void {
    this.lines.set(fn(this.lines()));
    if (!this.browser) return;
    try {
      localStorage.setItem(KEY, JSON.stringify(this.lines()));
    } catch {
      /* хранилище недоступно — корзина останется в памяти */
    }
  }

  private restore(): CartLine[] {
    if (!this.browser) return [];
    try {
      const raw = JSON.parse(localStorage.getItem(KEY) ?? '[]');
      return Array.isArray(raw) ? raw.filter((l) => l && typeof l.product_id === 'number' && l.quantity > 0) : [];
    } catch {
      return [];
    }
  }
}
