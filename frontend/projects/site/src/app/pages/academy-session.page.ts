import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';

import { ApiService } from '@shared/services/api.service';
import { AuthService } from '@shared/services/auth.service';
import { AcademySession } from '@shared/models/api.models';
import { errorText, pluralize } from '@shared/utils';
import { HttpStatusService } from '../services/http-status.service';
import { SeoService } from '../services/seo.service';
import { ToastService } from '../services/toast.service';

@Component({
    selector: 'app-academy-session',
    imports: [RouterLink],
    template: `
    @if (session(); as s) {
      <article class="wrap band">
        <nav class="crumbs muted">
          <a routerLink="/">Главная</a> / <a routerLink="/academy">Академия</a> / <span>{{ s.title }}</span>
        </nav>

        <header class="top">
          <div class="shot">
            @if (s.cover) {
              <img [src]="s.cover.url" [alt]="s.cover.alt || s.title">
            } @else {
              <div class="ph" aria-hidden="true">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="1.2">
                  <rect x="3" y="5" width="18" height="12" rx="2.5" />
                  <path d="m10.5 9 4 3-4 3z" /><path d="M8 20h8" />
                </svg>
              </div>
            }
            @if (s.is_live_now) { <span class="live">Идёт сейчас</span> }
          </div>

          <div class="intro">
            <div class="row">
              <span class="chip chip-g">{{ s.format_label }}</span>
              <span class="chip">{{ s.level_label }}</span>
              <span class="chip">{{ peopleLabel(s.registrations_count) }}</span>
            </div>

            <h1>{{ s.title }}</h1>
            @if (s.lead) { <p class="lead muted">{{ s.lead }}</p> }

            <dl class="facts">
              @if (s.host_name) {
                <div>
                  <dt>Ведёт</dt>
                  <dd>
                    {{ s.host_name }}
                    @if (s.host_title) { <span class="muted role">{{ s.host_title }}</span> }
                  </dd>
                </div>
              }
              @if (s.starts_at) {
                <div><dt>Начало</dt><dd>{{ dateTime(s.starts_at) }}</dd></div>
              }
              @if (s.duration_minutes) {
                <div><dt>Длительность</dt><dd class="tnum">{{ s.duration_minutes }} минут</dd></div>
              }
            </dl>

            <div class="actions">
              @if (!auth.isAuthenticated()) {
                <div class="note">
                  Запись на занятия открыта читателям с подтверждённой почтой: по ней мы присылаем
                  напоминание перед эфиром и открываем ссылку на запись. Пароль придумывать не нужно —
                  вход по ссылке из письма.
                  <a class="btn btn-green btn-sm cta" routerLink="/register">Создать аккаунт</a>
                </div>
              } @else if (!auth.isVerified()) {
                <div class="note note-warn">
                  Подтвердите адрес почты — мы отправили письмо со ссылкой. После этого можно
                  записаться на занятие.
                </div>
              } @else {
                <div class="row buttons">
                  @if (s.viewer.registered) {
                    <button class="btn btn-ghost" type="button" [disabled]="saving()"
                            (click)="unregister(s)">
                      {{ saving() ? 'Отменяем…' : 'Отменить запись' }}
                    </button>
                  } @else {
                    <button class="btn btn-green" type="button" [disabled]="saving()"
                            (click)="register(s)">
                      {{ saving() ? 'Записываем…' : 'Записаться' }}
                    </button>
                  }

                  <!-- Ссылки приходят в ответе только когда смотреть действительно можно. -->
                  @if (s.is_live_now && s.stream_url) {
                    <a class="btn btn-primary" [href]="s.stream_url!" target="_blank" rel="noopener">
                      Смотреть эфир
                    </a>
                  }
                  @if (s.record_url) {
                    <a class="btn btn-line" [href]="s.record_url!" target="_blank" rel="noopener">
                      Смотреть запись
                    </a>
                  }
                </div>

                <!-- Пока смотреть нечего, ссылок в ответе нет — объясняем, чего ждать. -->
                @if (s.viewer.registered && !s.stream_url && !s.record_url) {
                  <p class="muted small">
                    Вы записаны. Напомним письмом перед началом, а ссылка на эфир появится здесь,
                    когда занятие начнётся.
                  </p>
                } @else if (!s.viewer.registered && s.has_record) {
                  <p class="muted small">Запись уже готова — она откроется сразу после записи на занятие.</p>
                }
              }
            </div>
          </div>
        </header>

        @if (s.takeaways.length) {
          <section class="block">
            <div class="sec-head"><h2>Чему научитесь</h2></div>
            <ul class="takeaways">
              @for (t of s.takeaways; track $index) {
                <li>
                  <span class="dot" aria-hidden="true"></span>
                  <span>{{ t }}</span>
                </li>
              }
            </ul>
          </section>
        }

        @if (s.description) {
          <section class="block">
            <div class="sec-head"><h2>О занятии</h2></div>
            <p class="flow">{{ s.description }}</p>
          </section>
        }
      </article>
    } @else if (error()) {
      <div class="wrap band empty">
        <h1>Занятие не найдено</h1>
        <p class="muted">{{ error() }}</p>
        <a class="btn btn-green" routerLink="/academy">Всё расписание</a>
      </div>
    } @else {
      <div class="wrap band loading"><div class="spinner"></div></div>
    }
  `,
    styles: [`
    .crumbs { font-size: 13px; margin-bottom: 14px; }
    .top { display: flex; flex-wrap: wrap; gap: 30px; align-items: flex-start; }
    .shot {
      position: relative; flex: 1 1 380px; min-width: 0; height: 300px;
      border-radius: 20px; overflow: hidden; background: var(--sunken); display: grid; place-items: center;
    }
    .shot img { width: 100%; height: 100%; object-fit: cover; }
    .ph { width: 100%; height: 100%; background: var(--sunken); color: var(--muted); display: grid; place-items: center; }
    .live {
      position: absolute; left: 12px; top: 12px; padding: 6px 14px; border-radius: 999px;
      background: var(--brick); color: var(--on-accent); font-size: 12px; font-weight: 700;
    }
    .intro { flex: 999 1 420px; min-width: 0; display: flex; flex-direction: column; gap: 14px; }
    h1 { font-size: clamp(28px, 4.2vw, 42px); line-height: 1.1; }
    .lead { margin: 0; font-size: 17px; max-width: 62ch; }
    .facts { margin: 0; display: flex; flex-direction: column; gap: 8px; }
    .facts > div {
      display: flex; justify-content: space-between; gap: 14px; align-items: baseline;
      padding-bottom: 8px; border-bottom: 1px solid var(--line);
    }
    .facts dt { font-size: 13px; color: var(--muted); }
    .facts dd { margin: 0; font-size: 15px; font-weight: 700; text-align: right; }
    .role { display: block; font-size: 13px; font-weight: 400; }
    .actions { display: flex; flex-direction: column; gap: 10px; }
    .buttons { align-items: center; }
    .cta { margin-top: 10px; }
    .small { margin: 0; font-size: 13px; }
    .block { margin-top: 44px; }
    .takeaways { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 12px; max-width: 70ch; }
    .takeaways li { display: flex; gap: 12px; align-items: flex-start; line-height: 1.6; }
    .dot { flex: 0 0 auto; width: 10px; height: 10px; margin-top: 7px; border-radius: 999px; background: var(--green); }
    .flow { margin: 0; white-space: pre-line; line-height: 1.7; max-width: 76ch; }
    .loading { display: grid; place-items: center; min-height: 50vh; }
  `]
})
export class AcademySessionPage implements OnInit {
  private api = inject(ApiService);
  private route = inject(ActivatedRoute);
  private seo = inject(SeoService);
  private status = inject(HttpStatusService);
  private toasts = inject(ToastService);
  auth = inject(AuthService);

  readonly session = signal<AcademySession | null>(null);
  readonly error = signal<string | null>(null);
  readonly saving = signal(false);

  ngOnInit(): void {
    this.route.paramMap.subscribe((params) => {
      const slug = params.get('slug');
      if (!slug) return;
      this.load(slug);
    });
  }

  private load(slug: string): void {
    this.session.set(null);
    this.error.set(null);

    this.api.academySession(slug).subscribe({
      next: (res) => {
        this.session.set(res.data);

        this.seo.apply({
          title: res.data.title,
          description: res.data.lead || res.data.description,
          image: res.data.cover?.url,
          path: `/academy/${res.data.slug}`,
          type: 'article',
        });
      },
      error: (err) => {
        this.error.set('Возможно, занятие ещё не объявлено или снято с публикации.');
        // Код 404 для робота: «не найдено» не должно попадать в индекс.
        this.status.fromError(err);
      },
    });
  }

  register(s: AcademySession): void {
    this.saving.set(true);

    this.api.registerForSession(s.slug).subscribe({
      next: (res) => {
        // В ответе приходит занятие целиком — вместе со ссылками, если смотреть уже можно.
        this.session.set(res.data);
        this.saving.set(false);
        this.toasts.show(
          res.data.is_upcoming
            ? 'Вы записаны. Напомним письмом перед началом.'
            : 'Вы записаны. Запись открыта — можно смотреть.',
        );
      },
      error: (err) => {
        this.saving.set(false);
        this.toasts.error(errorText(err));
      },
    });
  }

  unregister(s: AcademySession): void {
    this.saving.set(true);

    this.api.unregisterFromSession(s.slug).subscribe({
      next: () => {
        // Ответ отмены содержит только флаг, поэтому занятие перечитываем:
        // вместе с записью пропадают и ссылки на эфир.
        this.api.academySession(s.slug).subscribe({
          next: (res) => {
            this.session.set(res.data);
            this.saving.set(false);
          },
          error: () => this.saving.set(false),
        });
        this.toasts.show('Запись отменена');
      },
      error: (err) => {
        this.saving.set(false);
        this.toasts.error(errorText(err));
      },
    });
  }

  peopleLabel(n: number): string {
    return pluralize(n, 'участник', 'участника', 'участников');
  }

  /** 3 ноября, 19:00 — в часовом поясе читателя. */
  dateTime(iso: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    return d.toLocaleString('ru-RU', {
      day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
  }
}
