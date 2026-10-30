import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';

import { ApiService } from '@shared/services/api.service';
import { AuthService } from '@shared/services/auth.service';
import { Contest, ContestEntry, Media } from '@shared/models/api.models';
import { errorText, formatDate, pluralize } from '@shared/utils';
import { HttpStatusService } from '../services/http-status.service';
import { SeoService } from '../services/seo.service';
import { ToastService } from '../services/toast.service';

@Component({
    selector: 'app-contest',
    imports: [RouterLink, FormsModule],
    template: `
    @if (contest(); as c) {
      <article class="wrap band">
        <nav class="crumbs muted">
          <a routerLink="/">Главная</a> / <a routerLink="/contests">Конкурсы</a> / <span>{{ c.title }}</span>
        </nav>

        <header class="top">
          <div class="shot">
            @if (c.cover) {
              <img [src]="c.cover.url" [alt]="c.cover.alt || c.title">
            } @else {
              <div class="ph" aria-hidden="true">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="1.2">
                  <path d="M7 4h10v5a5 5 0 0 1-10 0z" /><path d="M12 14v4" />
                  <path d="M8.5 20h7" /><path d="M7 5H4.5v1.5a3 3 0 0 0 3 3" />
                  <path d="M17 5h2.5v1.5a3 3 0 0 1-3 3" />
                </svg>
              </div>
            }
          </div>

          <div class="intro">
            <div class="row">
              <span class="chip chip-g">{{ c.phase_label }}</span>
              <span class="chip">{{ c.kind_label }}</span>
              <span class="chip">{{ entriesLabel(c.entries_count) }}</span>
            </div>

            <h1>{{ c.title }}</h1>
            @if (c.lead) { <p class="lead muted">{{ c.lead }}</p> }
            <p class="hint">{{ c.phase_hint }}</p>

            @if (c.prize) {
              <div class="prize">
                <span class="k">Приз</span>
                <span class="v">{{ c.prize }}</span>
              </div>
            }

            <dl class="stages">
              @if (c.submissions_open_at) {
                <div><dt>Приём работ открыт</dt><dd>{{ date(c.submissions_open_at) }}</dd></div>
              }
              @if (c.submissions_close_at) {
                <div><dt>Приём работ закрыт</dt><dd>{{ date(c.submissions_close_at) }}</dd></div>
              }
              @if (c.voting_open_at) {
                <div><dt>Голосование открыто</dt><dd>{{ date(c.voting_open_at) }}</dd></div>
              }
              @if (c.voting_close_at) {
                <div><dt>Голосование закрыто</dt><dd>{{ date(c.voting_close_at) }}</dd></div>
              }
              @if (c.results_at) {
                <div><dt>Итоги</dt><dd>{{ date(c.results_at) }}</dd></div>
              }
            </dl>
          </div>
        </header>

        @if (c.winners?.length) {
          <section class="block">
            <div class="sec-head"><h2>Победители</h2></div>
            <div class="grid winners">
              @for (w of c.winners || []; track w.id) {
                <div class="card entry">
                  <div class="place">{{ placeLabel(w.place) }}</div>
                  @if (shot(w); as m) {
                    <div class="media">
                      @if (isVideo(c)) {
                        <video [src]="m.url" controls preload="metadata"
                               [attr.aria-label]="'Работа ' + (w.author?.name || 'участника')"></video>
                      } @else {
                        <img [src]="m.url" [alt]="m.alt || w.title || 'Работа участника'" loading="lazy">
                      }
                    </div>
                  }
                  @if (w.title) { <h3>{{ w.title }}</h3> }
                  <p class="muted who">
                    {{ w.author?.name || 'Участник' }}
                    @if (w.author?.rating_label) { · {{ w.author?.rating_label }} }
                  </p>
                  @if (w.body) { <p class="text">{{ w.body }}</p> }
                  <p class="muted votes tnum">{{ votesLabel(w.votes_count) }}</p>
                </div>
              }
            </div>
          </section>
        }

        @if (c.rules) {
          <section class="block">
            <div class="sec-head"><h2>Условия</h2></div>
            <p class="flow">{{ c.rules }}</p>
          </section>
        }

        @if (c.description) {
          <section class="block">
            <div class="sec-head"><h2>О конкурсе</h2></div>
            <p class="flow">{{ c.description }}</p>
          </section>
        }

        <section class="block">
          <div class="sec-head"><h2>Прислать работу</h2></div>

          @if (!auth.isAuthenticated()) {
            <div class="note">
              Работы принимаем от читателей с подтверждённой почтой. Пароль придумывать не нужно —
              вход по ссылке из письма.
              <a class="btn btn-green btn-sm cta" routerLink="/register">Создать аккаунт</a>
            </div>
          } @else if (!auth.isVerified()) {
            <div class="note note-warn">
              Подтвердите адрес почты — ссылку мы отправили письмом. После этого откроются
              отправка работ и голосование.
            </div>
          } @else if (canSubmit()) {
            <form class="form" (submit)="submit($event)">
              <p class="limits">{{ limitsText(c) }}</p>

              <label class="fl pick-row" for="files">Файлы работы
                <input id="files" type="file" name="files" [attr.accept]="accept(c)"
                       [attr.multiple]="maxFiles(c) > 1 ? '' : null" (change)="onFiles($event, c)">
              </label>

              @if (files().length) {
                <ul class="picked">
                  @for (f of files(); track f.name + f.size; let i = $index) {
                    <li>
                      <span class="fname">{{ f.name }}</span>
                      <span class="muted tnum fsize">{{ sizeMb(f) }} МБ</span>
                      <button class="drop" type="button" (click)="removeFile(i)"
                              [attr.aria-label]="'Убрать файл ' + f.name">Убрать</button>
                    </li>
                  }
                </ul>
              }

              <label class="fl" for="etitle">Заголовок, если нужен
                <input id="etitle" type="text" name="etitle" maxlength="120" [(ngModel)]="entryTitle"
                       placeholder="Например, тыквенный суп с хрустящим шалфеем">
              </label>

              <label class="fl" for="ebody">Описание, если нужно
                <textarea id="ebody" name="ebody" rows="3" maxlength="2000" [(ngModel)]="entryBody"
                          placeholder="Что готовили, что изменили в рецепте"></textarea>
              </label>

              <button class="btn btn-green send" type="submit" [disabled]="sending()">
                {{ sending() ? 'Отправляем…' : 'Отправить работу' }}
              </button>
              <p class="muted small">
                Работу увидят в галерее после проверки редакцией.
                @if (entriesLeft() > 0) { Осталось отправить: {{ entriesLeft() }}. }
              </p>
            </form>
          } @else {
            <p class="muted">
              {{ entriesLeft() === 0 && myEntries().length
                ? 'Вы уже прислали всё, что можно на этот конкурс.'
                : 'Приём работ на этот конкурс сейчас закрыт.' }}
            </p>
          }

          @if (myEntries().length) {
            <div class="mine">
              <h3>Ваши работы</h3>
              @for (m of myEntries(); track m.id) {
                <div class="my-entry">
                  <div class="row">
                    <span class="chip" [class.chip-b]="m.status === 'rejected'"
                          [class.chip-g]="m.status === 'approved'">{{ m.status_label }}</span>
                    @if (m.title) { <strong>{{ m.title }}</strong> }
                    <span class="muted when">{{ date(m.created_at) }}</span>
                  </div>
                  @if (m.status === 'rejected' && m.reject_reason) {
                    <p class="muted reason">Причина: {{ m.reject_reason }}</p>
                  }
                </div>
              }
            </div>
          }
        </section>

        <section class="block">
          <div class="sec-head">
            <h2>Работы участников</h2>
            <div class="tabs" role="group" aria-label="Сортировка работ">
              <button class="tab" type="button" [class.on]="sort() === 'votes'"
                      [attr.aria-pressed]="sort() === 'votes'" (click)="setSort('votes')">
                По голосам
              </button>
              <button class="tab" type="button" [class.on]="sort() === 'new'"
                      [attr.aria-pressed]="sort() === 'new'" (click)="setSort('new')">
                Новые
              </button>
            </div>
          </div>

          @if (!auth.isAuthenticated() && c.accepts_votes) {
            <div class="note">
              Голосуют читатели с подтверждённой почтой — один голос на конкурс.
              <a class="btn btn-green btn-sm cta" routerLink="/register">Создать аккаунт</a>
            </div>
          } @else if (voted() !== null) {
            <p class="muted">Ваш голос учтён. Переголосовать нельзя.</p>
          } @else if (c.accepts_votes && canVote()) {
            <p class="muted">У вас один голос. Выберите работу, которая нравится больше всех.</p>
          }

          @if (entriesLoading() && !entries().length) {
            <div class="loading"><div class="spinner"></div></div>
          } @else if (entries().length) {
            <div class="grid works">
              @for (e of entries(); track e.id) {
                <div class="card entry">
                  @if (shot(e); as m) {
                    <div class="media">
                      @if (isVideo(c)) {
                        <video [src]="m.url" controls preload="metadata"
                               [attr.aria-label]="'Работа ' + (e.author?.name || 'участника')"></video>
                      } @else {
                        <img [src]="m.url" [alt]="m.alt || e.title || 'Работа участника'" loading="lazy">
                      }
                    </div>

                    @if (!isVideo(c) && rest(e).length) {
                      <div class="strip">
                        @for (x of rest(e); track x.id) {
                          <img [src]="x.url" [alt]="x.alt || 'Ещё кадр работы'" loading="lazy">
                        }
                      </div>
                    }
                  }

                  @if (e.title) { <h3>{{ e.title }}</h3> }
                  <p class="muted who">
                    {{ e.author?.name || 'Участник' }}
                    @if (e.author?.rating_label) { · {{ e.author?.rating_label }} }
                  </p>
                  @if (e.body) { <p class="text">{{ e.body }}</p> }

                  <div class="foot">
                    <span class="muted votes tnum">{{ votesLabel(e.votes_count) }}</span>

                    @if (voted() === e.id) {
                      <span class="chip chip-g">Ваш голос</span>
                    } @else if (voted() !== null) {
                      <button class="btn btn-ghost" type="button" disabled>Голос отдан</button>
                    } @else if (canVote()) {
                      <button class="btn btn-green" type="button"
                              [disabled]="voting() !== null || isMine(e)"
                              [attr.aria-label]="'Голосовать за работу ' + (e.title || e.author?.name || 'участника')"
                              (click)="vote(c, e)">
                        {{ isMine(e) ? 'Это ваша работа' : voting() === e.id ? 'Голосуем…' : 'Голосовать' }}
                      </button>
                    }
                  </div>
                </div>
              }
            </div>

            @if (entriesPage() < entriesLast()) {
              <div class="more">
                <button class="btn btn-ghost" type="button" [disabled]="entriesLoading()"
                        (click)="moreEntries(c)">
                  {{ entriesLoading() ? 'Загружаем…' : 'Показать ещё' }}
                </button>
              </div>
            }
          } @else {
            <div class="empty">
              <h3>Работ в галерее пока нет</h3>
              <p class="muted">
                {{ c.accepts_entries
                  ? 'Приём открыт — ваша работа может стать первой в галерее.'
                  : 'Присланные работы появляются здесь после проверки редакцией.' }}
              </p>
            </div>
          }
        </section>
      </article>
    } @else if (error()) {
      <div class="wrap band empty">
        <h1>Конкурс не найден</h1>
        <p class="muted">{{ error() }}</p>
        <a class="btn btn-green" routerLink="/contests">Все конкурсы</a>
      </div>
    } @else {
      <div class="wrap band loading"><div class="spinner"></div></div>
    }
  `,
    styles: [`
    .crumbs { font-size: 13px; margin-bottom: 14px; }
    .top { display: flex; flex-wrap: wrap; gap: 30px; align-items: flex-start; }
    .shot {
      flex: 1 1 380px; min-width: 0; height: 320px; border-radius: 20px;
      overflow: hidden; background: var(--sunken); display: grid; place-items: center;
    }
    .shot img { width: 100%; height: 100%; object-fit: cover; }
    .ph { width: 100%; height: 100%; background: var(--green-soft); color: var(--green-ink); display: grid; place-items: center; }
    .intro { flex: 999 1 420px; min-width: 0; display: flex; flex-direction: column; gap: 14px; }
    h1 { font-size: clamp(28px, 4.2vw, 42px); line-height: 1.1; }
    .lead { margin: 0; font-size: 17px; max-width: 62ch; }
    .hint { margin: 0; font-weight: 600; color: var(--green); }
    .prize {
      display: flex; flex-direction: column; gap: 4px; background: var(--brick-soft);
      color: var(--brick-ink); border-radius: 14px; padding: 14px 16px;
    }
    .prize .k, .facts .k { font-size: 11px; letter-spacing: .1em; text-transform: uppercase; }
    .prize .v { font-size: 17px; font-weight: 700; }
    .stages { margin: 0; display: flex; flex-direction: column; gap: 8px; }
    .stages > div {
      display: flex; justify-content: space-between; gap: 14px; align-items: baseline;
      padding-bottom: 8px; border-bottom: 1px solid var(--line);
    }
    .stages dt { font-size: 13px; color: var(--muted); }
    .stages dd { margin: 0; font-size: 14px; font-weight: 700; text-align: right; }
    .block { margin-top: 44px; }
    .flow { margin: 0; white-space: pre-line; line-height: 1.7; max-width: 76ch; }
    .winners, .works { grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }
    .entry { gap: 10px; padding: 14px; }
    .place {
      align-self: flex-start; background: var(--green); color: var(--on-accent);
      border-radius: 999px; padding: 5px 14px; font-size: 12px; font-weight: 700;
    }
    .media { border-radius: 12px; overflow: hidden; background: var(--sunken); }
    .media img, .media video { width: 100%; max-height: 260px; object-fit: cover; display: block; }
    .strip { display: flex; gap: 6px; overflow-x: auto; }
    .strip img {
      flex: 0 0 72px; height: 56px; object-fit: cover;
      border-radius: 8px; background: var(--sunken);
    }
    .entry h3 { font-family: var(--body); font-size: 17px; font-weight: 700; }
    .who { margin: 0; font-size: 13px; }
    .text { margin: 0; font-size: 14px; line-height: 1.6; }
    .votes { margin: 0; font-size: 13px; font-weight: 700; }
    .foot {
      display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between;
      margin-top: auto; padding-top: 10px; border-top: 1px solid var(--line);
    }
    .form {
      background: var(--surface); border: 1px solid var(--line); border-radius: 18px;
      padding: 20px; display: flex; flex-direction: column; gap: 14px; max-width: 560px;
    }
    .limits { margin: 0; font-size: 13px; color: var(--muted); }
    .pick-row input[type="file"] { font: inherit; min-height: 44px; }
    .picked { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 8px; }
    .picked li {
      display: flex; align-items: center; gap: 10px; background: var(--sunken);
      border-radius: 12px; padding: 8px 12px; font-size: 14px;
    }
    .fname { flex: 1 1 auto; min-width: 0; overflow-wrap: anywhere; }
    .fsize { flex: 0 0 auto; font-size: 13px; }
    .drop {
      flex: 0 0 auto; min-height: 44px; padding: 0 10px; border: 0; background: none;
      color: var(--brick); font-weight: 600;
    }
    .send { align-self: flex-start; }
    .small { margin: 0; font-size: 13px; }
    .cta { margin-top: 10px; }
    .mine { margin-top: 24px; display: flex; flex-direction: column; gap: 12px; max-width: 560px; }
    .mine h3 { font-family: var(--body); font-size: 17px; font-weight: 700; }
    .my-entry { border: 1px solid var(--line); border-radius: 14px; padding: 12px 14px; background: var(--surface); }
    .my-entry .row { align-items: center; }
    .when { font-size: 13px; }
    .reason { margin: 8px 0 0; font-size: 13px; }
    .tabs { display: flex; flex-wrap: wrap; gap: 8px; }
    .tab {
      min-height: 44px; padding: 0 16px; border-radius: 999px; border: 1px solid var(--line);
      background: var(--surface); color: var(--muted); font-weight: 600;
    }
    .tab.on { background: var(--green); border-color: var(--green); color: var(--on-accent); }
    .loading { display: grid; place-items: center; min-height: 220px; }
    .more { display: flex; justify-content: center; margin-top: 26px; }
  `]
})
export class ContestPage implements OnInit {
  /** Номер запроса: ответ, отставший от нового отбора, игнорируем,
   *  иначе он перезапишет свежий список устаревшим. */
  private loadSeq = 0;

  private api = inject(ApiService);
  private route = inject(ActivatedRoute);
  private seo = inject(SeoService);
  private status = inject(HttpStatusService);
  private toasts = inject(ToastService);
  auth = inject(AuthService);

  readonly contest = signal<Contest | null>(null);
  readonly error = signal<string | null>(null);

  readonly entries = signal<ContestEntry[]>([]);
  readonly entriesPage = signal(1);
  readonly entriesLast = signal(1);
  readonly entriesLoading = signal(true);
  readonly sort = signal<'votes' | 'new'>('votes');

  /** Состояние посетителя держим отдельно: оно меняется после голоса и отправки. */
  readonly canVote = signal(false);
  readonly canSubmit = signal(false);
  readonly voted = signal<number | null>(null);
  readonly voting = signal<number | null>(null);
  readonly entriesLeft = signal(0);
  readonly myEntries = signal<ContestEntry[]>([]);

  readonly files = signal<File[]>([]);
  readonly sending = signal(false);

  entryTitle = '';
  entryBody = '';

  ngOnInit(): void {
    this.route.paramMap.subscribe((params) => {
      const slug = params.get('slug');
      if (!slug) return;
      this.load(slug);
    });
  }

  private load(slug: string): void {
    this.contest.set(null);
    this.error.set(null);

    this.api.contest(slug).subscribe({
      next: (res) => {
        const c = res.data;
        this.contest.set(c);
        this.canVote.set(c.viewer.can_vote);
        this.canSubmit.set(c.viewer.can_submit);
        this.voted.set(c.viewer.voted_entry_id ?? null);
        this.entriesLeft.set(c.viewer.entries_left);
        this.myEntries.set(c.viewer.my_entries ?? []);

        this.seo.apply({
          title: c.title,
          description: c.lead || c.rules,
          image: c.cover?.url,
          path: `/contests/${c.slug}`,
          type: 'article',
        });
      },
      error: (err) => {
        this.error.set('Возможно, конкурс ещё не объявлен или снят с публикации.');
        // Код 404 для робота: «не найдено» не должно попадать в индекс.
        this.status.fromError(err);
      },
    });

    this.loadEntries(slug, 1);
  }

  private loadEntries(slug: string, page: number): void {
    this.entriesLoading.set(true);

    const seq = ++this.loadSeq;

    this.api.contestEntries(slug, this.sort(), page).subscribe({
      next: (res) => {
        if (seq !== this.loadSeq) return;

        this.entries.set(page === 1 ? res.data : [...this.entries(), ...res.data]);
        this.entriesPage.set(res.meta.current_page);
        this.entriesLast.set(res.meta.last_page);
        this.entriesLoading.set(false);
      },
      error: () => {
        if (seq !== this.loadSeq) return;

        if (page === 1) this.entries.set([]);
        this.entriesLoading.set(false);
      },
    });
  }

  setSort(sort: 'votes' | 'new'): void {
    if (this.sort() === sort) return;
    const c = this.contest();
    this.sort.set(sort);
    if (c) this.loadEntries(c.slug, 1);
  }

  moreEntries(c: Contest): void {
    this.loadEntries(c.slug, this.entriesPage() + 1);
  }

  vote(c: Contest, entry: ContestEntry): void {
    if (this.voting() !== null || this.voted() !== null) return;

    this.voting.set(entry.id);

    this.api.voteForEntry(c.slug, entry.id).subscribe({
      next: (res) => {
        this.voting.set(null);
        this.voted.set(res.data.entry_id);
        this.canVote.set(false);
        this.entries.update((list) =>
          list.map((e) => (e.id === res.data.entry_id ? { ...e, votes_count: res.data.votes_count } : e)),
        );
        this.toasts.show('Голос учтён. Переголосовать нельзя.');
      },
      error: (err) => {
        this.voting.set(null);
        // Текст уже человеческий: сервер отвечает по-русски на already_voted,
        // voting_closed и own_entry — показываем его как есть.
        this.toasts.error(errorText(err));

        const code = this.errorCode(err);
        if (code === 'already_voted' || code === 'voting_closed') this.canVote.set(false);
      },
    });
  }

  onFiles(event: Event, c: Contest): void {
    const input = event.target as HTMLInputElement;
    const picked = Array.from(input.files ?? []);
    input.value = '';
    if (!picked.length) return;

    const limit = this.maxMb(c) * 1024 * 1024;
    const fit = picked.filter((f) => f.size <= limit);
    if (fit.length < picked.length) {
      this.toasts.error(`Каждый файл должен быть меньше ${this.maxMb(c)} МБ`);
    }

    const room = this.maxFiles(c) - this.files().length;
    if (room <= 0) {
      this.toasts.error(`К работе можно приложить не больше ${this.maxFiles(c)} файлов`);
      return;
    }

    const next = fit.slice(0, room);
    if (fit.length > room) {
      this.toasts.error(`К работе можно приложить не больше ${this.maxFiles(c)} файлов`);
    }
    if (!next.length) return;

    this.files.update((list) => [...list, ...next]);
  }

  removeFile(index: number): void {
    this.files.update((list) => list.filter((_, i) => i !== index));
  }

  submit(event: Event): void {
    event.preventDefault();

    const c = this.contest();
    if (!c) return;

    if (!this.files().length) {
      this.toasts.error('Приложите хотя бы один файл');
      return;
    }

    this.sending.set(true);

    this.api
      .submitEntry(
        c.slug,
        this.files(),
        this.entryTitle.trim() || undefined,
        this.entryBody.trim() || undefined,
      )
      .subscribe({
        next: (res) => {
          this.sending.set(false);
          this.myEntries.update((list) => [res.data, ...list]);
          this.files.set([]);
          this.entryTitle = '';
          this.entryBody = '';

          const left = Math.max(0, this.entriesLeft() - 1);
          this.entriesLeft.set(left);
          this.canSubmit.set(left > 0);

          this.toasts.show('Работа отправлена. Она появится в галерее после проверки.');
        },
        error: (err) => {
          this.sending.set(false);
          this.toasts.error(errorText(err));
        },
      });
  }

  /** Первый файл работы — главный кадр карточки. */
  shot(entry: ContestEntry): Media | null {
    const media = entry.media;
    return media && media.length ? media[0] : null;
  }

  /** Остальные снимки работы: показываем лентой, чтобы ничего не потерялось. */
  rest(entry: ContestEntry): Media[] {
    return (entry.media ?? []).slice(1);
  }

  /** Видео отдаётся тем же media, поэтому тип разметки выбираем по типу конкурса. */
  isVideo(c: Contest): boolean {
    return c.kind === 'video';
  }

  maxFiles(c: Contest): number {
    return c.kind === 'video' ? 1 : 5;
  }

  maxMb(c: Contest): number {
    return c.kind === 'video' ? 100 : 8;
  }

  accept(c: Contest): string {
    return c.kind === 'video' ? 'video/mp4,video/webm,video/quicktime' : 'image/jpeg,image/png,image/webp';
  }

  limitsText(c: Contest): string {
    return c.kind === 'video'
      ? 'Один видеофайл до 100 МБ: mp4, webm или mov.'
      : 'До пяти снимков, каждый меньше 8 МБ: jpeg, png или webp.';
  }

  sizeMb(file: File): string {
    return (file.size / (1024 * 1024)).toFixed(1).replace('.', ',');
  }

  isMine(entry: ContestEntry): boolean {
    return this.myEntries().some((m) => m.id === entry.id);
  }

  placeLabel(place: number | null): string {
    if (place === 1) return 'Первое место';
    if (place === 2) return 'Второе место';
    if (place === 3) return 'Третье место';
    return 'Призёр';
  }

  entriesLabel(n: number): string {
    return pluralize(n, 'работа', 'работы', 'работ');
  }

  votesLabel(n: number): string {
    return pluralize(n, 'голос', 'голоса', 'голосов');
  }

  date(iso: string | null): string {
    return formatDate(iso);
  }

  private errorCode(err: unknown): string {
    const e = err as { error?: { code?: string } };
    return e?.error?.code ?? '';
  }
}
