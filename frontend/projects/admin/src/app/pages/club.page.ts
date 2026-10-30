import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';

import { AdminClubItem, CommerceService } from '@shared/services/commerce.service';
import { errorText, plural } from '@shared/utils';
import { ToastService } from '../services/toast.service';

type KindFilter = '' | AdminClubItem['kind'];

const KINDS: { value: KindFilter; label: string }[] = [
  { value: '', label: 'Все' },
  { value: 'video', label: 'Видео' },
  { value: 'masterclass', label: 'Мастер-классы' },
  { value: 'recipe', label: 'Рецепты' },
  { value: 'material', label: 'Материалы' },
];

@Component({
    selector: 'adm-club',
    imports: [RouterLink],
    template: `
    <header class="head">
      <div>
        <h1>Закрытая академия</h1>
        <p class="muted">{{ rows().length }} {{ plural(rows().length, 'материал', 'материала', 'материалов') }} для подписчиков</p>
      </div>
      <a class="btn btn-green" routerLink="/club/new">Новый материал</a>
    </header>

    <div class="filters" role="group" aria-label="Тип материала">
      @for (k of kinds; track k.value) {
        <button class="toggle" type="button" [class.on]="kind() === k.value"
                [attr.aria-pressed]="kind() === k.value" (click)="kind.set(k.value)">{{ k.label }}</button>
      }
    </div>

    @if (loading() && !rows().length) {
      <div class="loading"><div class="spinner"></div></div>
    } @else if (visible().length) {
      <div class="scroll">
        <table>
          <thead>
            <tr>
              <th>Обложка</th>
              <th>Материал</th>
              <th>Тип</th>
              <th class="num">Длительность</th>
              <th>Видео</th>
              <th>Статус</th>
              <th class="right">Действия</th>
            </tr>
          </thead>
          <tbody>
            @for (c of visible(); track c.id) {
              <tr>
                <td>
                  @if (c.cover; as cv) {
                    <img class="thumb" [src]="cv.url" [alt]="cv.alt || c.title" loading="lazy">
                  } @else {
                    <div class="thumb ph muted">—</div>
                  }
                </td>
                <td>
                  <a class="name" [routerLink]="['/club', c.id]">{{ c.title }}</a>
                  @if (c.chef_name) { <div class="muted small">{{ c.chef_name }}</div> }
                </td>
                <td class="muted">{{ c.kind_label }}</td>
                <td class="num tnum">{{ c.duration_minutes ? c.duration_minutes + ' мин' : '—' }}</td>
                <td>
                  @if (c.has_video) { <span class="chip chip-g">Есть</span> } @else { <span class="muted">—</span> }
                </td>
                <td>
                  <span class="chip" [class.chip-g]="c.is_published">{{ c.is_published ? 'Опубликован' : 'Черновик' }}</span>
                </td>
                <td class="right">
                  <a class="btn btn-ghost btn-sm" [routerLink]="['/club', c.id]">Открыть</a>
                </td>
              </tr>
            }
          </tbody>
        </table>
      </div>
    } @else {
      <div class="empty">
        <h3>Здесь пока пусто</h3>
        <p class="muted">Поменяйте фильтр или добавьте материал.</p>
        <a class="btn btn-green" routerLink="/club/new">Новый материал</a>
      </div>
    }
  `,
    styles: [`
    .head { display: flex; flex-wrap: wrap; gap: 16px; align-items: center; justify-content: space-between; margin-bottom: 18px; }
    h1 { font-size: 30px; }
    .head p { margin: 4px 0 0; font-size: 14px; }
    .filters { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 16px; }
    .toggle {
      min-height: 44px; padding: 0 16px; border: 1px solid var(--line); border-radius: 999px;
      background: var(--surface); color: var(--muted); font-size: 14px;
    }
    .toggle.on { border-color: var(--green); background: var(--green-soft); color: var(--green-ink); font-weight: 700; }
    .thumb { width: 72px; height: 48px; object-fit: cover; border-radius: 8px; background: var(--sunken); display: block; }
    .ph { display: grid; place-items: center; border: 1px solid var(--line); font-size: 12px; }
    .name { font-weight: 600; }
    .small { font-size: 12px; }
    .num { text-align: right; }
    .right { text-align: right; }
    .loading { display: grid; place-items: center; min-height: 30vh; }
    .btn-sm { min-height: 44px; }
  `]
})
export class ClubPage implements OnInit {
  private loadSeq = 0;

  private shop = inject(CommerceService);
  private toasts = inject(ToastService);

  readonly kinds = KINDS;
  readonly kind = signal<KindFilter>('');
  readonly rows = signal<AdminClubItem[]>([]);
  readonly loading = signal(true);

  /** Список приходит целиком — тип фильтруем на месте. */
  readonly visible = computed(() => {
    const k = this.kind();
    return k ? this.rows().filter((r) => r.kind === k) : this.rows();
  });

  readonly plural = plural;

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    const seq = ++this.loadSeq;

    this.shop.adminClub().subscribe({
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
