import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';

import { ApiService } from '@shared/services/api.service';
import { AcademyRegistrationRow, AcademySession } from '@shared/models/api.models';
import { errorText, formatDate, plural } from '@shared/utils';
import { ToastService } from '../services/toast.service';

@Component({
    selector: 'adm-academy-registrations',
    imports: [RouterLink],
    template: `
    <nav class="crumbs muted">
      <a routerLink="/academy">Академия</a> /
      @if (session(); as s) {
        <a [routerLink]="['/academy', s.id]">{{ s.title }}</a> /
      }
      <span>Записавшиеся</span>
    </nav>

    <header class="head">
      <div>
        <h1>Записавшиеся</h1>
        <p class="muted">
          {{ total() }} {{ plural(total(), 'запись', 'записи', 'записей') }}
          @if (session(); as s) { · {{ s.title }} }
        </p>
      </div>
    </header>

    @if (loading() && !rows().length) {
      <div class="loading"><div class="spinner"></div></div>
    } @else if (rows().length) {
      <div class="scroll">
        <table>
          <thead>
            <tr>
              <th>Имя</th>
              <th>Почта</th>
              <th>Почта подтверждена</th>
              <th>Записался</th>
              <th>Напоминание</th>
              <th>Смотрел</th>
            </tr>
          </thead>
          <tbody>
            @for (r of rows(); track r.id) {
              <tr>
                <td><strong>{{ r.user.name || 'Аккаунт удалён' }}</strong></td>
                <td class="muted">{{ r.user.email || '—' }}</td>
                <td>
                  @if (r.user.verified) {
                    <span class="ok">Да</span>
                  } @else {
                    <span class="bad">Нет</span>
                  }
                </td>
                <td class="muted">{{ formatDate(r.created_at) || '—' }}</td>
                <td class="muted">
                  {{ r.reminded_at ? formatDate(r.reminded_at) : 'не отправляли' }}
                </td>
                <td class="muted">
                  {{ r.watched_at ? formatDate(r.watched_at) : 'нет' }}
                </td>
              </tr>
            }
          </tbody>
        </table>
      </div>

      @if (page() < lastPage()) {
        <div class="more">
          <button class="btn btn-ghost" type="button" [disabled]="loading()" (click)="load(page() + 1)">
            Показать ещё
          </button>
        </div>
      }
    } @else {
      <div class="empty">
        <h3>Никто ещё не записался</h3>
        <p class="muted">Записи появятся здесь после публикации занятия.</p>
      </div>
    }
  `,
    styles: [`
    .crumbs { font-size: 13px; margin-bottom: 12px; }
    .head { margin-bottom: 18px; }
    h1 { font-size: 30px; }
    .head p { margin: 4px 0 0; font-size: 14px; }
    .ok { color: var(--green); font-weight: 600; }
    .bad { color: var(--brick-ink); font-weight: 600; }
    .more { display: flex; justify-content: center; margin-top: 18px; }
    .loading { display: grid; place-items: center; min-height: 30vh; }
  `]
})
export class AcademyRegistrationsPage implements OnInit {
  /** Номер запроса: ответ, отставший от нового отбора, игнорируем,
   *  иначе он перезапишет свежий список устаревшим. */
  private loadSeq = 0;

  private api = inject(ApiService);
  private route = inject(ActivatedRoute);
  private toasts = inject(ToastService);

  readonly sessionId = signal(0);
  readonly session = signal<AcademySession | null>(null);
  readonly rows = signal<AcademyRegistrationRow[]>([]);
  readonly total = signal(0);
  readonly page = signal(1);
  readonly lastPage = signal(1);
  readonly loading = signal(true);

  readonly formatDate = formatDate;
  readonly plural = plural;

  ngOnInit(): void {
    this.sessionId.set(Number(this.route.snapshot.paramMap.get('id')));

    // Название занятия нужно только для заголовка и крошек.
    this.api.adminAcademySession(this.sessionId()).subscribe({
      next: (res) => this.session.set(res.data),
      error: () => this.session.set(null),
    });

    this.load();
  }

  load(page = 1): void {
    this.loading.set(true);
    const seq = ++this.loadSeq;

    this.api.academyRegistrations(this.sessionId(), page).subscribe({
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
}
