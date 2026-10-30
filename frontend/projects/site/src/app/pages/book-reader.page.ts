import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';

import { BookChapterText } from '@shared/models/commerce.models';
import { CommerceService } from '@shared/services/commerce.service';
import { errorText } from '@shared/utils';
import { SeoService } from '../services/seo.service';

/** Читалка: одна глава на экране, текст узкой колонкой, переход по главам. */
@Component({
    selector: 'app-book-reader',
    imports: [RouterLink],
    template: `
    <div class="wrap band reader">
      @if (chapter(); as c) {
        <nav class="crumbs muted">
          <a [routerLink]="['/books', c.book.slug]">{{ c.book.title }}</a> / <span>Глава {{ c.position }}</span>
        </nav>
        <article>
          <h1>{{ c.title }}</h1>
          @for (p of paragraphs(); track $index) { <p>{{ p }}</p> }
        </article>
        <nav class="pager" aria-label="Главы">
          @if (c.prev) {
            <a class="btn btn-ghost" [routerLink]="['/books', c.book.slug, 'read', c.prev]">← Предыдущая</a>
          } @else { <span></span> }
          @if (c.next) {
            <a class="btn btn-green" [routerLink]="['/books', c.book.slug, 'read', c.next]">Следующая →</a>
          } @else {
            <a class="btn btn-ghost" [routerLink]="['/books', c.book.slug]">К содержанию</a>
          }
        </nav>
      } @else if (locked()) {
        <div class="locked">
          <h1>Глава закрыта</h1>
          <p class="muted">{{ locked() }}</p>
          <a class="btn btn-green" [routerLink]="['/books', slug()]">К книге</a>
        </div>
      } @else {
        <div class="spinner"></div>
      }
    </div>
  `,
    styles: [`
    .reader { max-width: 720px; }
    .crumbs { font-size: 13px; margin-bottom: 18px; }
    h1 { font-size: clamp(26px, 4vw, 36px); line-height: 1.15; margin-bottom: 22px; }
    article p { margin: 0 0 1em; font-size: 18px; line-height: 1.75; }
    .pager { display: flex; justify-content: space-between; gap: 12px; margin-top: 32px; padding-top: 20px; border-top: 1px solid var(--line); }
    .locked { display: flex; flex-direction: column; gap: 12px; align-items: flex-start; }
  `]
})
export class BookReaderPage implements OnInit {
  private commerce = inject(CommerceService);
  private route = inject(ActivatedRoute);
  private seo = inject(SeoService);

  readonly chapter = signal<BookChapterText | null>(null);
  readonly locked = signal('');
  readonly slug = signal('');
  readonly paragraphs = computed(() =>
    (this.chapter()?.body ?? '').split(/\n{2,}/).map((p) => p.trim()).filter(Boolean));

  ngOnInit(): void {
    this.route.paramMap.subscribe((p) => {
      const slug = p.get('slug')!;
      const position = Number(p.get('position'));
      this.slug.set(slug);
      this.chapter.set(null);
      this.locked.set('');

      this.commerce.chapter(slug, position).subscribe({
        next: (res) => {
          this.chapter.set(res.data);
          // Платный текст в поисковик не отдаём.
          this.seo.apply({ title: `${res.data.title} — ${res.data.book.title}`, path: `/books/${slug}/read/${position}`, noindex: true });
          if (typeof window !== 'undefined') window.scrollTo({ top: 0 });
        },
        error: (err) => this.locked.set(errorText(err, 'Глава недоступна.')),
      });
    });
  }
}
