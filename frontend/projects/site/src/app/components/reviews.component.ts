import { Component, Input, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';

import { ApiService } from '@shared/services/api.service';
import { AuthService } from '@shared/services/auth.service';
import { Review } from '@shared/models/api.models';
import { errorText, formatShortDate, pluralize } from '@shared/utils';
import { ToastService } from '../services/toast.service';
import { StarsComponent } from './stars.component';

@Component({
    selector: 'app-reviews',
    imports: [FormsModule, RouterLink, StarsComponent],
    template: `
    <div class="sec-head">
      <h2>Отзывы@if (total()) { · {{ total() }} }</h2>
      @if (average(); as avg) {
        <div class="row avg">
          <app-stars [value]="avg" [size]="18" />
          <span class="muted">{{ avg.toFixed(1).replace('.', ',') }} из 5</span>
        </div>
      }
    </div>

    @if (!auth.isAuthenticated()) {
      <div class="note">
        Отзывы оставляют читатели с подтверждённой почтой.
        <a class="btn btn-green btn-sm form-cta" routerLink="/register">Создать аккаунт</a>
      </div>
    } @else if (!auth.isVerified()) {
      <div class="note note-warn">Подтвердите адрес почты — мы отправили письмо со ссылкой.</div>
    } @else if (sent()) {
      <div class="note">Спасибо, отзыв опубликован.</div>
    } @else {
      <form class="form" (submit)="submit($event)">
        <h3>Приготовили? Расскажите, как вышло</h3>

        <div class="rate">
          <span class="muted label">Оценка</span>
          @for (n of [1, 2, 3, 4, 5]; track n) {
            <button type="button" class="star-btn" (click)="rating.set(n)"
                    [attr.aria-label]="'Поставить ' + n + ' из 5'">
              <svg width="26" height="26" viewBox="0 0 24 24"
                   [attr.fill]="n <= rating() ? 'var(--brick)' : 'none'"
                   [attr.stroke]="n <= rating() ? 'var(--brick)' : 'var(--line)'" stroke-width="1.6">
                <path d="m12 3.6 2.6 5.4 5.9.8-4.3 4.1 1.1 5.9L12 17l-5.3 2.8 1.1-5.9-4.3-4.1 5.9-.8z" />
              </svg>
            </button>
          }
        </div>

        <label class="fl">Что получилось
          <textarea [(ngModel)]="body" name="body" rows="3"
                    placeholder="Что изменили, что пошло не так, сколько времени заняло"></textarea>
        </label>

        <div class="drop" [class.over]="dragging()"
             (dragover)="onDragOver($event)" (dragleave)="dragging.set(false)" (drop)="onDrop($event)">
          Перетащите фото сюда или
          <label class="pick">выберите на устройстве
            <input type="file" accept="image/*" multiple (change)="onFiles($event)" hidden>
          </label>
          <div class="hint">До трёх снимков, каждый меньше 8 МБ</div>
        </div>

        @if (photos().length) {
          <div class="shots">
            @for (p of previews(); track p.url; let i = $index) {
              <button type="button" (click)="removePhoto(i)" title="Убрать снимок">
                <img [src]="p.url" [alt]="'Прикреплённый снимок ' + (i + 1)">
              </button>
            }
          </div>
        }

        <button class="btn btn-green submit" type="submit" [disabled]="saving()">
          {{ saving() ? 'Отправляем…' : 'Опубликовать отзыв' }}
        </button>
      </form>
    }

    @if (reviews().length) {
      <div class="list">
        @for (r of reviews(); track r.id) {
          <article class="review">
            <div class="ava">{{ initial(r) }}</div>
            <div class="body">
              <div class="row head">
                <strong>{{ r.author?.name }}</strong>
                <app-stars [value]="r.rating" />
                <span class="muted date">{{ date(r.created_at) }}</span>
              </div>
              <p class="text">{{ r.body }}</p>
              @if (r.media?.length) {
                <div class="shots">
                  @for (m of r.media; track m.id) {
                    <a [href]="m.url" target="_blank" rel="noopener">
                      <img [src]="m.url" [alt]="'Фото от ' + (r.author?.name || 'читателя')" loading="lazy">
                    </a>
                  }
                </div>
              }
            </div>
          </article>
        }
      </div>
    } @else if (!loading()) {
      <p class="muted">Пока никто не отчитался. Будете первым — приложите фото, оно попадёт в галерею.</p>
    }
  `,
    styles: [`
    .avg { align-items: center; }
    .form {
      background: var(--surface); border: 1px solid var(--line); border-radius: 18px;
      padding: 20px; display: flex; flex-direction: column; gap: 14px; margin-bottom: 22px;
    }
    .form h3 { font-family: var(--body); font-size: 17px; font-weight: 700; }
    .form-cta { margin-top: 10px; }
    .rate { display: flex; align-items: center; gap: 2px; flex-wrap: wrap; }
    .rate .label { font-weight: 600; margin-right: 8px; font-size: 14px; }
    .star-btn { width: 44px; height: 44px; border: 0; background: none; padding: 0; display: grid; place-items: center; }
    .drop { border: 1.5px dashed var(--line); border-radius: 14px; padding: 18px; text-align: center; color: var(--muted); font-size: 14px; }
    .drop.over { border-color: var(--green); background: var(--green-soft); color: var(--green-ink); }
    .pick { color: var(--green); font-weight: 700; cursor: pointer; text-decoration: underline; }
    .hint { margin-top: 6px; font-size: 12px; }
    .shots { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
    .shots button, .shots a {
      width: 92px; height: 72px; border-radius: 10px; overflow: hidden;
      border: 1px solid var(--line); background: var(--sunken); padding: 0; display: block;
    }
    .shots img { width: 100%; height: 100%; object-fit: cover; }
    .submit { align-self: flex-start; }
    .review { display: flex; gap: 14px; padding: 18px 0; border-bottom: 1px solid var(--line); }
    .ava {
      flex: 0 0 auto; width: 44px; height: 44px; border-radius: 999px;
      background: var(--green-soft); color: var(--green-ink); display: grid; place-items: center; font-weight: 700;
    }
    .body { min-width: 0; flex: 1 1 auto; }
    .head { align-items: center; gap: 10px; }
    .date { font-size: 13px; }
    .text { margin: 8px 0 0; line-height: 1.6; }
  `]
})
export class ReviewsComponent implements OnInit {
  @Input({ required: true }) slug!: string;
  @Input() ratingAverage: number | null = null;
  @Input() reviewsCount = 0;

  private api = inject(ApiService);
  private toasts = inject(ToastService);
  auth = inject(AuthService);

  readonly reviews = signal<Review[]>([]);
  readonly total = signal(0);
  readonly loading = signal(true);
  readonly saving = signal(false);
  readonly sent = signal(false);
  readonly rating = signal(0);
  readonly photos = signal<File[]>([]);
  readonly previews = signal<{ url: string }[]>([]);
  readonly dragging = signal(false);

  body = '';

  ngOnInit(): void {
    this.total.set(this.reviewsCount);
    this.api.reviews(this.slug).subscribe({
      next: (res) => {
        this.reviews.set(res.data);
        this.total.set(res.meta.total);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  average(): number | null {
    if (this.ratingAverage) return this.ratingAverage;
    const list = this.reviews();
    if (!list.length) return null;
    return list.reduce((s, r) => s + r.rating, 0) / list.length;
  }

  initial(r: Review): string {
    return (r.author?.name || '?').charAt(0).toUpperCase();
  }

  date(iso: string | null): string {
    return formatShortDate(iso);
  }

  onDragOver(event: DragEvent): void {
    event.preventDefault();
    this.dragging.set(true);
  }

  onDrop(event: DragEvent): void {
    event.preventDefault();
    this.dragging.set(false);
    if (event.dataTransfer?.files) this.addFiles(event.dataTransfer.files);
  }

  onFiles(event: Event): void {
    const input = event.target as HTMLInputElement;
    if (input.files) this.addFiles(input.files);
    input.value = '';
  }

  private addFiles(files: FileList): void {
    const room = 3 - this.photos().length;
    const picked = Array.from(files).filter((f) => f.type.startsWith('image/')).slice(0, room);
    if (!picked.length) return;

    this.photos.update((list) => [...list, ...picked]);
    this.previews.update((list) => [
      ...list,
      ...picked.map((f) => ({ url: URL.createObjectURL(f) })),
    ]);
  }

  removePhoto(i: number): void {
    const preview = this.previews()[i];
    if (preview) URL.revokeObjectURL(preview.url);
    this.photos.update((list) => list.filter((_, k) => k !== i));
    this.previews.update((list) => list.filter((_, k) => k !== i));
  }

  submit(event: Event): void {
    event.preventDefault();
    if (!this.rating()) {
      this.toasts.error('Поставьте оценку от 1 до 5');
      return;
    }
    if (this.body.trim().length < 10) {
      this.toasts.error('Напишите пару предложений — так отзыв полезнее');
      return;
    }

    this.saving.set(true);
    this.api.addReview(this.slug, this.rating(), this.body.trim(), this.photos()).subscribe({
      next: (res) => {
        this.reviews.update((list) => [res.data, ...list]);
        this.total.update((n) => n + 1);
        this.sent.set(true);
        this.saving.set(false);
        this.toasts.show(
          res.data.media?.length ? 'Отзыв опубликован, фото ушло в галерею' : 'Отзыв опубликован',
        );
      },
      error: (err) => {
        this.saving.set(false);
        this.toasts.error(errorText(err));
      },
    });
  }

  countLabel(): string {
    return pluralize(this.total(), 'отзыв', 'отзыва', 'отзывов');
  }
}
