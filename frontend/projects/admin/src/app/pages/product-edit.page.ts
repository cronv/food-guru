import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';

import { ApiService } from '@shared/services/api.service';
import { CommerceService } from '@shared/services/commerce.service';
import { Product } from '@shared/models/commerce.models';
import { errorText } from '@shared/utils';
import { ToastService } from '../services/toast.service';

type AdminProduct = Product & { stock: number; is_published: boolean; cover_media_id: number | null };

/** Обложка: из медиатеки приходит Media, из товара — cover + cover_media_id. */
interface CoverRef { id: number; url: string; alt: string | null }

/** Рубли из поля ввода → копейки для API. Пустое поле — null. */
function toKop(value: number | string | null | undefined): number | null {
  if (value === null || value === undefined || value === '') return null;
  const n = Number(value);
  return Number.isFinite(n) ? Math.round(n * 100) : null;
}

/** Копейки → рубли для поля ввода. */
function toRub(kop: number | null | undefined): number | null {
  return kop === null || kop === undefined ? null : kop / 100;
}

@Component({
    selector: 'adm-product-edit',
    imports: [RouterLink, FormsModule],
    template: `
    <nav class="crumbs muted">
      <a routerLink="/products">Магазин</a> / <span>{{ title || 'Новый товар' }}</span>
    </nav>

    <header class="head">
      <div class="row ident">
        <h1>{{ title || 'Новый товар' }}</h1>
        @if (id()) {
          <span class="chip" [class.chip-g]="savedPublished()">{{ savedPublished() ? 'В продаже' : 'Скрыт' }}</span>
        }
      </div>
      <div class="row">
        <button class="btn btn-green" type="button" [disabled]="saving()" (click)="save()">
          {{ saving() ? 'Сохраняем…' : 'Сохранить' }}
        </button>
      </div>
    </header>

    <div class="layout">
      <div class="main">
        <section class="panel">
          <h2>Основное</h2>

          <label class="fl" for="p-title">Название
            <input id="p-title" type="text" name="p-title" [(ngModel)]="title" placeholder="Например, чугунная сковорода 26 см">
          </label>

          <label class="fl" for="p-slug">Адрес
            <input id="p-slug" type="text" name="p-slug" [(ngModel)]="slug" placeholder="chugunnaya-skovoroda">
            <span class="muted weak">Пустое поле — адрес соберётся из названия.</span>
          </label>

          <div class="two">
            <label class="fl" for="p-cat">Категория
              <input id="p-cat" type="text" name="p-cat" [(ngModel)]="category" placeholder="Посуда">
            </label>
            <label class="fl" for="p-sku">Артикул
              <input id="p-sku" type="text" name="p-sku" [(ngModel)]="sku" placeholder="FG-0001">
            </label>
          </div>

          <!-- Эти три поля — фильтры магазина: пишите одинаково, «Нержавеющая сталь», а не «нерж. сталь». -->
          <div class="two">
            <label class="fl" for="p-mat">Материал
              <input id="p-mat" type="text" name="p-mat" [(ngModel)]="material" placeholder="Нержавеющая сталь">
            </label>
            <label class="fl" for="p-country">Страна производства
              <input id="p-country" type="text" name="p-country" [(ngModel)]="country" placeholder="Германия">
            </label>
            <label class="fl" for="p-color">Цвет
              <input id="p-color" type="text" name="p-color" [(ngModel)]="color" placeholder="Стальной">
            </label>
          </div>

          <label class="fl" for="p-lead">Краткое описание
            <textarea id="p-lead" name="p-lead" rows="2" [(ngModel)]="lead"
                      placeholder="Одна-две фразы для карточки товара"></textarea>
          </label>

          <label class="fl" for="p-desc">Полное описание
            <textarea id="p-desc" name="p-desc" rows="7" [(ngModel)]="description"
                      placeholder="Материал, размеры, уход, комплектация"></textarea>
          </label>
        </section>
      </div>

      <aside class="side">
        <section class="panel">
          <h2>Цена и остаток</h2>
          <div class="two">
            <label class="fl" for="p-price">Цена, ₽
              <input id="p-price" type="number" name="p-price" min="1" step="0.01" [(ngModel)]="price">
            </label>
            <label class="fl" for="p-old">Старая цена, ₽
              <input id="p-old" type="number" name="p-old" min="0" step="0.01" [(ngModel)]="oldPrice">
            </label>
          </div>
          <span class="muted weak">Старая цена зачёркивается на сайте; она должна быть больше текущей. Пусто — без скидки.</span>

          <label class="fl" for="p-stock">Остаток, шт.
            <input id="p-stock" type="number" name="p-stock" min="0" step="1" [(ngModel)]="stock">
          </label>

          <label class="check">
            <input type="checkbox" name="p-pub" [(ngModel)]="isPublished"> Показывать в магазине
          </label>
        </section>

        <section class="panel">
          <h2>Фото</h2>
          @if (cover(); as c) {
            <div class="cover">
              <img [src]="c.url" [alt]="c.alt || 'Фото товара'">
              <button class="btn btn-ghost btn-sm" type="button" (click)="cover.set(null)">Убрать</button>
            </div>
          } @else {
            <label class="upload">
              {{ uploading() ? 'Загружаем…' : '+ Загрузить фото' }}
              <input class="hidden-file" type="file" accept="image/*"
                     [disabled]="uploading()" (change)="uploadCover($event)">
            </label>
          }
          <p class="muted weak">JPEG, PNG или WebP до 8 МБ.</p>
        </section>
      </aside>
    </div>
  `,
    styles: [`
    .crumbs { font-size: 13px; margin-bottom: 12px; }
    .head { display: flex; flex-wrap: wrap; gap: 16px; align-items: center; justify-content: space-between; margin-bottom: 20px; }
    .ident { align-items: center; gap: 14px; min-width: 0; }
    h1 { font-size: 28px; overflow-wrap: anywhere; }
    .layout { display: flex; flex-wrap: wrap; gap: 22px; align-items: flex-start; }
    .main { flex: 999 1 520px; min-width: 0; display: flex; flex-direction: column; gap: 20px; }
    .side { flex: 1 1 300px; min-width: 0; display: flex; flex-direction: column; gap: 20px; }
    .panel {
      background: var(--surface); border: 1px solid var(--line); border-radius: 16px;
      padding: 22px; display: flex; flex-direction: column; gap: 14px;
    }
    .panel h2 { font-family: var(--body); font-size: 18px; font-weight: 700; }
    .panel p { margin: 0; }
    .weak { font-weight: 400; font-size: 13px; }
    .two { display: flex; flex-wrap: wrap; gap: 12px; }
    .two label { flex: 1 1 140px; min-width: 0; }
    .check { display: flex; align-items: center; gap: 10px; font-size: 15px; }
    .cover { display: flex; flex-direction: column; gap: 10px; align-items: flex-start; }
    .cover img { border-radius: 12px; max-height: 220px; max-width: 100%; }
    .upload {
      position: relative; border: 1.5px dashed var(--line); border-radius: 12px; padding: 24px; text-align: center;
      color: var(--muted); font-weight: 600; cursor: pointer; min-height: 44px;
    }
    .hidden-file { position: absolute; width: 1px; height: 1px; min-height: 0; padding: 0; opacity: 0; }
    .upload:focus-within { outline: 2px solid var(--brick); outline-offset: 2px; }
    .btn-sm { min-height: 44px; }
  `]
})
export class ProductEditPage implements OnInit {
  private api = inject(ApiService);
  private shop = inject(CommerceService);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private toasts = inject(ToastService);

  readonly id = signal<number | null>(null);
  readonly saving = signal(false);
  readonly uploading = signal(false);
  readonly cover = signal<CoverRef | null>(null);
  /** Статус из последнего ответа сервера, а не из несохранённой галочки. */
  readonly savedPublished = signal(false);

  title = '';
  slug = '';
  category = '';
  material = '';
  country = '';
  color = '';
  sku = '';
  lead = '';
  description = '';
  price: number | null = null;
  oldPrice: number | null = null;
  stock: number | null = 0;
  isPublished = false;

  ngOnInit(): void {
    const param = this.route.snapshot.paramMap.get('id');
    if (param && param !== 'new') {
      this.id.set(Number(param));
      this.shop.adminProduct(Number(param)).subscribe({
        next: (res) => this.fill(res.data),
        error: (err) => this.toasts.error(errorText(err, 'Товар не найден или нет доступа.')),
      });
    }
  }

  private fill(p: AdminProduct): void {
    this.title = p.title;
    this.slug = p.slug;
    this.category = p.category ?? '';
    this.material = p.material ?? '';
    this.country = p.country ?? '';
    this.color = p.color ?? '';
    this.sku = p.sku ?? '';
    this.lead = p.lead ?? '';
    this.description = p.description ?? '';
    this.price = toRub(p.price);
    this.oldPrice = toRub(p.old_price);
    this.stock = p.stock;
    this.isPublished = p.is_published;
    this.savedPublished.set(p.is_published);
    this.cover.set(p.cover && p.cover_media_id ? { id: p.cover_media_id, url: p.cover.url, alt: p.cover.alt } : null);
  }

  uploadCover(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;

    this.uploading.set(true);
    this.api.uploadMedia(file, this.title).subscribe({
      next: (res) => {
        this.cover.set({ id: res.data.id, url: res.data.url, alt: res.data.alt });
        this.uploading.set(false);
      },
      error: (err) => {
        this.uploading.set(false);
        this.toasts.error(errorText(err));
      },
    });
  }

  /** Проверки на клиенте повторяют правила сервера — чтобы не гонять заведомо неверное. */
  private validate(price: number | null, oldPrice: number | null): string | null {
    if (!this.title.trim()) return 'Укажите название';
    if (price === null || price < 100) return 'Цена — не меньше 1 ₽';
    if (oldPrice !== null && oldPrice <= price) return 'Старая цена должна быть больше текущей';
    if (this.stock === null || this.stock < 0 || !Number.isInteger(Number(this.stock))) return 'Остаток — целое число от нуля';
    return null;
  }

  save(): void {
    const price = toKop(this.price);
    const oldPrice = toKop(this.oldPrice) || null;
    const problem = this.validate(price, oldPrice);
    if (problem) {
      this.toasts.error(problem);
      return;
    }

    this.saving.set(true);
    const id = this.id() ?? undefined;
    this.shop.saveProduct({
      title: this.title.trim(),
      slug: this.slug.trim() || undefined,
      category: this.category.trim() || null,
      material: this.material.trim() || null,
      country: this.country.trim() || null,
      color: this.color.trim() || null,
      sku: this.sku.trim() || null,
      lead: this.lead.trim() || null,
      description: this.description.trim() || null,
      price,
      old_price: oldPrice,
      stock: Number(this.stock),
      cover_media_id: this.cover()?.id ?? null,
      is_published: this.isPublished,
    }, id).subscribe({
      next: (res) => {
        this.saving.set(false);
        this.toasts.show('Сохранено');
        this.slug = res.data.slug ?? this.slug;
        this.savedPublished.set(this.isPublished);
        if (!id) {
          this.id.set(res.data.id);
          this.router.navigate(['/products', res.data.id], { replaceUrl: true });
        }
      },
      error: (err) => {
        this.saving.set(false);
        this.toasts.error(errorText(err));
      },
    });
  }
}
