import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';

import { Product, rub } from '@shared/models/commerce.models';
import { CommerceService } from '@shared/services/commerce.service';
import { CartService } from '../services/cart.service';
import { HttpStatusService } from '../services/http-status.service';
import { SeoService } from '../services/seo.service';
import { productSchema } from '../services/structured-data';
import { ToastService } from '../services/toast.service';

@Component({
    selector: 'app-product',
    imports: [RouterLink],
    template: `
    <div class="wrap band">
      @if (product(); as p) {
        <nav class="crumbs muted"><a routerLink="/shop">Магазин</a> / <span>{{ p.title }}</span></nav>
        <div class="top">
          <div class="shot">@if (p.cover) { <img [src]="p.cover.url" [alt]="p.cover.alt || p.title"> }</div>
          <div class="info">
            @if (p.category) { <span class="chip">{{ p.category }}</span> }
            <h1>{{ p.title }}</h1>
            <div class="price">
              <span class="now tnum">{{ rub(p.price) }}</span>
              @if (p.old_price) { <s class="muted tnum">{{ rub(p.old_price) }}</s> }
            </div>
            @if (!p.in_stock) {
              <p class="muted">Нет в наличии.</p>
            } @else {
              @if (p.stock_left) { <p class="low">Осталось {{ p.stock_left }} шт.</p> }
              <div class="row">
                <button class="btn btn-green" type="button" (click)="add(p)">
                  {{ cart.quantityOf(p.id) ? 'Добавить ещё' : 'В корзину' }}
                </button>
                @if (cart.quantityOf(p.id)) {
                  <a class="btn btn-ghost" routerLink="/cart">В корзине · {{ cart.quantityOf(p.id) }}</a>
                }
              </div>
            }
            @for (para of paragraphs(p); track $index) { <p class="desc">{{ para }}</p> }
            @if (p.material || p.country || p.color) {
              <dl class="props">
                @if (p.material) { <div><dt>Материал</dt><dd>{{ p.material }}</dd></div> }
                @if (p.country) { <div><dt>Страна производства</dt><dd>{{ p.country }}</dd></div> }
                @if (p.color) { <div><dt>Цвет</dt><dd>{{ p.color }}</dd></div> }
              </dl>
            }
            @if (p.sku) { <p class="muted small">Артикул {{ p.sku }}</p> }
          </div>
        </div>
      } @else if (error()) {
        <h1>Товар не найден</h1>
        <a class="btn btn-ghost" routerLink="/shop">В магазин</a>
      } @else {
        <div class="spinner"></div>
      }
    </div>
  `,
    styles: [`
    .crumbs { font-size: 13px; margin-bottom: 16px; }
    .top { display: flex; flex-wrap: wrap; gap: 32px; align-items: flex-start; }
    .shot { flex: 1 1 340px; max-width: 520px; aspect-ratio: 1; border-radius: 20px; overflow: hidden; background: var(--sunken); }
    .shot img { width: 100%; height: 100%; object-fit: cover; }
    .info { flex: 1 1 320px; min-width: 0; display: flex; flex-direction: column; gap: 12px; align-items: flex-start; }
    h1 { font-size: clamp(26px, 4vw, 38px); line-height: 1.15; }
    .price { display: flex; align-items: baseline; gap: 10px; }
    .now { font-family: var(--display); font-size: 32px; }
    .low { margin: 0; color: var(--brick-ink); font-weight: 600; }
    .desc { margin: 0; max-width: 60ch; line-height: 1.6; }
    .small { margin: 0; font-size: 13px; }
    .props { margin: 0; width: 100%; max-width: 420px; }
    .props div { display: flex; justify-content: space-between; gap: 16px; padding: 8px 0; border-bottom: 1px solid var(--line); font-size: 14px; }
    .props dt { color: var(--muted); }
    .props dd { margin: 0; font-weight: 600; text-align: right; }
  `]
})
export class ProductPage implements OnInit {
  private commerce = inject(CommerceService);
  private route = inject(ActivatedRoute);
  private seo = inject(SeoService);
  private status = inject(HttpStatusService);
  private toasts = inject(ToastService);
  readonly cart = inject(CartService);
  readonly rub = rub;

  readonly product = signal<Product | null>(null);
  readonly error = signal(false);

  ngOnInit(): void {
    const slug = this.route.snapshot.paramMap.get('slug')!;
    this.commerce.product(slug).subscribe({
      next: (res) => {
        this.product.set(res.data);
        this.seo.apply({ title: res.data.title, description: res.data.lead, image: res.data.cover?.url, path: `/shop/${slug}`, type: 'product' });
        this.seo.breadcrumbs([
          { name: 'Главная', path: '/' },
          { name: 'Магазин', path: '/shop' },
          { name: res.data.title, path: `/shop/${slug}` },
        ]);
        this.seo.jsonLd('product', productSchema(this.seo, res.data));
      },
      error: (err) => {
        this.error.set(true);
        // Код 404 для робота: «не найдено» не должно попадать в индекс.
        this.status.fromError(err);
      },
    });
  }

  add(p: Product): void {
    this.cart.add(p);
    this.toasts.show(`«${p.title}» в корзине`);
  }

  paragraphs(p: Product): string[] {
    return (p.description ?? '').split(/\n{2,}/).map((s) => s.trim()).filter(Boolean);
  }
}
