import { Component, OnInit, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';

import { AdminBook, CommerceService } from '@shared/services/commerce.service';
import { rub } from '@shared/models/commerce.models';
import { errorText, plural } from '@shared/utils';
import { ToastService } from '../services/toast.service';

@Component({
    selector: 'adm-books',
    imports: [RouterLink],
    template: `
    <header class="head">
      <div>
        <h1>Книги</h1>
        <p class="muted">{{ rows().length }} {{ plural(rows().length, 'книга', 'книги', 'книг') }}</p>
      </div>
      <a class="btn btn-green" routerLink="/books/new">Новая книга</a>
    </header>

    @if (loading() && !rows().length) {
      <div class="loading"><div class="spinner"></div></div>
    } @else if (rows().length) {
      <div class="scroll">
        <table>
          <thead>
            <tr>
              <th>Обложка</th>
              <th>Книга</th>
              <th class="num">Цена</th>
              <th>Подписка</th>
              <th class="num">Глав</th>
              <th class="num">Покупок</th>
              <th>Статус</th>
              <th class="right">Действия</th>
            </tr>
          </thead>
          <tbody>
            @for (b of rows(); track b.id) {
              <tr>
                <td>
                  @if (b.cover; as c) {
                    <img class="thumb" [src]="c.url" [alt]="c.alt || b.title" loading="lazy">
                  } @else {
                    <div class="thumb ph muted">—</div>
                  }
                </td>
                <td>
                  <a class="name" [routerLink]="['/books', b.id]">{{ b.title }}</a>
                  @if (b.author_name) { <div class="muted small">{{ b.author_name }}</div> }
                </td>
                <td class="num tnum">{{ b.price ? rub(b.price) : 'Бесплатно' }}</td>
                <td>{{ b.in_subscription ? 'Входит' : '—' }}</td>
                <td class="num tnum">{{ b.chapters_count }}</td>
                <td class="num tnum">{{ b.purchases_count }}</td>
                <td>
                  <span class="chip" [class.chip-g]="b.is_published">{{ b.is_published ? 'Опубликована' : 'Черновик' }}</span>
                </td>
                <td class="right">
                  <a class="btn btn-ghost btn-sm" [routerLink]="['/books', b.id]">Открыть</a>
                </td>
              </tr>
            }
          </tbody>
        </table>
      </div>
    } @else {
      <div class="empty">
        <h3>Книг пока нет</h3>
        <p class="muted">Добавьте первую книгу — её можно продавать или включить в подписку.</p>
        <a class="btn btn-green" routerLink="/books/new">Новая книга</a>
      </div>
    }
  `,
    styles: [`
    .head { display: flex; flex-wrap: wrap; gap: 16px; align-items: center; justify-content: space-between; margin-bottom: 18px; }
    h1 { font-size: 30px; }
    .head p { margin: 4px 0 0; font-size: 14px; }
    .thumb { width: 44px; height: 58px; object-fit: cover; border-radius: 8px; background: var(--sunken); display: block; }
    .ph { display: grid; place-items: center; border: 1px solid var(--line); font-size: 12px; }
    .name { font-weight: 600; }
    .small { font-size: 12px; }
    .num { text-align: right; }
    .right { text-align: right; }
    .loading { display: grid; place-items: center; min-height: 30vh; }
    .btn-sm { min-height: 44px; }
  `]
})
export class BooksPage implements OnInit {
  private loadSeq = 0;

  private shop = inject(CommerceService);
  private toasts = inject(ToastService);

  readonly rows = signal<AdminBook[]>([]);
  readonly loading = signal(true);

  readonly rub = rub;
  readonly plural = plural;

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    const seq = ++this.loadSeq;

    this.shop.adminBooks().subscribe({
      next: (res) => {
        if (seq !== this.loadSeq) return;
        this.rows.set(res.data);
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
