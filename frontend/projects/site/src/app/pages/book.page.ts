import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';

import { BookDetail, InsufficientFunds, insufficientFunds, rub } from '@shared/models/commerce.models';
import { AuthService } from '@shared/services/auth.service';
import { CommerceService } from '@shared/services/commerce.service';
import { errorText, pluralize } from '@shared/utils';
import { FundsNoticeComponent } from '../components/funds-notice.component';
import { HttpStatusService } from '../services/http-status.service';
import { SeoService } from '../services/seo.service';
import { bookSchema } from '../services/structured-data';
import { ToastService } from '../services/toast.service';

@Component({
    selector: 'app-book',
    imports: [RouterLink, FundsNoticeComponent],
    template: `
    <div class="wrap band">
      @if (book(); as b) {
        <nav class="crumbs muted"><a routerLink="/books">Книги</a> / <span>{{ b.title }}</span></nav>

        <div class="top">
          <div class="cover">
            @if (b.cover) { <img [src]="b.cover.url" [alt]="b.cover.alt || b.title"> }
          </div>

          <div class="info">
            <h1>{{ b.title }}</h1>
            @if (b.author_name) { <p class="author">{{ b.author_name }}</p> }
            @if (b.description) { <p class="muted desc">{{ b.description }}</p> }
            <p class="muted small">{{ chaptersLabel(b.chapters.length) }}</p>

            <div class="buy">
              @if (b.is_free) {
                <span class="price">Бесплатно</span>
              } @else if (b.owned) {
                <span class="price ok">Книга у вас</span>
              } @else {
                <span class="price tnum">{{ rub(b.price) }}</span>
                @if (b.in_subscription) {
                  <span class="muted small">или читать по подписке на <a routerLink="/club">закрытую академию</a></span>
                }
              }

              <div class="row">
                @if (b.can_read) {
                  <a class="btn btn-green" [routerLink]="['/books', b.slug, 'read', firstChapter()]">Читать</a>
                } @else {
                  <button class="btn btn-green" type="button" [disabled]="busy()" (click)="buy(b)">
                    {{ busy() ? 'Покупаем…' : 'Купить за ' + rub(b.price) }}
                  </button>
                  @if (previewChapter(); as pos) {
                    <a class="btn btn-ghost" [routerLink]="['/books', b.slug, 'read', pos]">Читать отрывок</a>
                  }
                }
                @if (b.can_download) {
                  <a class="btn btn-ghost" [href]="pdfUrl(b.slug)" download>Скачать PDF</a>
                }
              </div>

              <app-funds-notice [info]="funds()" [back]="'/books/' + b.slug" />

              @if (!b.is_free && b.can_read && !b.owned) {
                <p class="muted small">Открыта по подписке. Скачать PDF можно после покупки.</p>
              }
              @if (b.can_download && !b.is_free) {
                <p class="muted small">В PDF на каждой странице — ваша почта: так файл защищён от раздачи.</p>
              }
            </div>
          </div>
        </div>

        <section class="toc">
          <h2>Содержание</h2>
          <ol>
            @for (c of b.chapters; track c.position) {
              <li>
                @if (c.readable) {
                  <a [routerLink]="['/books', b.slug, 'read', c.position]">{{ c.title }}</a>
                } @else {
                  <span class="locked">{{ c.title }}</span>
                }
                @if (c.is_preview && !b.is_free) { <span class="chip chip-g">отрывок</span> }
                @if (!c.readable) {
                  <svg class="lock" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                       stroke-width="2" aria-label="Закрыто"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                }
              </li>
            }
          </ol>
        </section>
      } @else if (error()) {
        <h1>Книга не найдена</h1>
        <a class="btn btn-ghost" routerLink="/books">Ко всем книгам</a>
      } @else {
        <div class="spinner"></div>
      }
    </div>
  `,
    styles: [`
    .crumbs { font-size: 13px; margin-bottom: 16px; }
    .top { display: flex; flex-wrap: wrap; gap: 32px; align-items: flex-start; }
    .cover {
      flex: 0 1 260px; aspect-ratio: 2 / 3; border-radius: 6px 16px 16px 6px; overflow: hidden;
      background: var(--sunken); box-shadow: 0 16px 36px rgb(42 28 20 / .2);
    }
    .cover img { width: 100%; height: 100%; object-fit: cover; }
    .info { flex: 1 1 340px; min-width: 0; display: flex; flex-direction: column; gap: 10px; }
    h1 { font-size: clamp(28px, 4vw, 42px); line-height: 1.1; }
    .author { margin: 0; font-weight: 600; }
    .desc { margin: 0; max-width: 62ch; font-size: 16px; }
    .small { margin: 0; font-size: 13px; }
    .buy {
      margin-top: 8px; padding: 18px; border-radius: 18px; background: var(--surface); border: 1px solid var(--line);
      display: flex; flex-direction: column; gap: 12px;
    }
    .price { font-family: var(--display); font-size: 28px; }
    .price.ok { color: var(--green-ink); }
    .toc { margin-top: 36px; max-width: 720px; }
    .toc h2 { font-size: 22px; margin-bottom: 10px; }
    .toc ol { margin: 0; padding-left: 22px; }
    .toc li { padding: 8px 0; border-bottom: 1px solid var(--line); display: list-item; }
    .toc li > * { margin-right: 8px; vertical-align: middle; }
    .locked { color: var(--muted); }
    .lock { color: var(--muted); }
  `]
})
export class BookPage implements OnInit {
  private commerce = inject(CommerceService);
  private route = inject(ActivatedRoute);
  private seo = inject(SeoService);
  private status = inject(HttpStatusService);
  private toasts = inject(ToastService);
  private auth = inject(AuthService);
  readonly rub = rub;

  readonly book = signal<BookDetail | null>(null);
  readonly error = signal(false);
  readonly busy = signal(false);
  readonly funds = signal<InsufficientFunds | null>(null);

  ngOnInit(): void {
    this.route.paramMap.subscribe((p) => this.load(p.get('slug')!));
  }

  private load(slug: string): void {
    this.commerce.book(slug).subscribe({
      next: (res) => {
        this.book.set(res.data);
        this.seo.apply({
          title: res.data.title,
          description: res.data.lead ?? undefined,
          image: res.data.cover?.url,
          path: `/books/${slug}`,
          type: 'book',
        });
        this.seo.breadcrumbs([
          { name: 'Главная', path: '/' },
          { name: 'Книги', path: '/books' },
          { name: res.data.title, path: `/books/${slug}` },
        ]);
        this.seo.jsonLd('book', bookSchema(this.seo, res.data));
      },
      error: (err) => {
        this.error.set(true);
        // Код 404 для робота: «не найдено» не должно попадать в индекс.
        this.status.fromError(err);
      },
    });
  }

  firstChapter(): number {
    return this.book()?.chapters[0]?.position ?? 1;
  }

  previewChapter(): number | null {
    return this.book()?.chapters.find((c) => c.readable)?.position ?? null;
  }

  buy(b: BookDetail): void {
    if (!this.auth.isAuthenticated()) {
      this.toasts.show('Войдите, чтобы купить книгу');
      return;
    }
    if (!this.auth.isVerified()) {
      this.toasts.show('Сначала подтвердите адрес почты');
      return;
    }

    this.busy.set(true);
    this.funds.set(null);
    this.commerce.buyBook(b.slug).subscribe({
      next: () => {
        this.busy.set(false);
        this.toasts.show('Книга ваша — приятного чтения');
        this.load(b.slug);
      },
      error: (err) => {
        this.busy.set(false);
        const lack = insufficientFunds(err);
        if (lack) this.funds.set(lack);
        else this.toasts.error(errorText(err));
      },
    });
  }

  pdfUrl(slug: string): string {
    return this.commerce.bookPdfUrl(slug);
  }

  chaptersLabel(n: number): string {
    return pluralize(n, 'глава', 'главы', 'глав');
  }
}
