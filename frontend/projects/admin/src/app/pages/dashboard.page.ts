import { Component, OnInit, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';

import { ApiService } from '@shared/services/api.service';
import { AuthService } from '@shared/services/auth.service';
import { AdminStats } from '@shared/models/api.models';
import { errorText } from '@shared/utils';
import { ToastService } from '../services/toast.service';

@Component({
    selector: 'adm-dashboard',
    imports: [RouterLink],
    template: `
    <header class="head">
      <div>
        <h1>Обзор</h1>
        <p class="muted">{{ today }} · данные за последние 7 дней</p>
      </div>
      <a class="btn btn-green" routerLink="/recipes/new">Новый рецепт</a>
    </header>

    @if (stats(); as s) {
      <div class="tiles">
        <div class="tile">
          <span class="k">Опубликовано рецептов</span>
          <span class="v tnum">{{ s.published }}</span>
          <span class="d good">+{{ s.published_week }} за неделю</span>
        </div>
        <div class="tile">
          <span class="k">Ждут модерации</span>
          <span class="v tnum">{{ s.moderation }}</span>
          @if (s.moderation_stale) {
            <span class="d warn">{{ s.moderation_stale }} висят дольше 3 дней</span>
          } @else {
            <span class="d muted">Просроченных нет</span>
          }
        </div>
        <div class="tile">
          <span class="k">Новых пользователей</span>
          <span class="v tnum">{{ s.users_week }}</span>
          <span class="d muted">всего {{ s.users_total }}</span>
        </div>
        <div class="tile">
          <span class="k">Подтвердили email</span>
          <span class="v tnum">{{ s.verified_share !== null ? s.verified_share + '%' : '—' }}</span>
          <span class="d muted">{{ s.unverified }} без подтверждения</span>
        </div>
      </div>

      <h2 class="group">Конкурсы, академия и журнал</h2>
      <div class="tiles">
        <a class="tile" routerLink="/contests" [queryParams]="{ status: 'published' }">
          <span class="k">Конкурсов идёт</span>
          <span class="v tnum">{{ s.contests_live }}</span>
          <span class="d muted">Открыть список →</span>
        </a>
        <a class="tile" routerLink="/contests">
          <span class="k">Работ ждут проверки</span>
          <span class="v tnum">{{ s.contest_entries_pending }}</span>
          <span class="d" [class.warn]="s.contest_entries_pending > 0"
                [class.muted]="!s.contest_entries_pending">
            @if (s.contest_entries_pending) { Перейти к разбору → } @else { Всё разобрано }
          </span>
        </a>
        <a class="tile" routerLink="/academy" [queryParams]="{ status: 'published' }">
          <span class="k">Занятий впереди</span>
          <span class="v tnum">{{ s.academy_upcoming }}</span>
          <span class="d muted">Открыть расписание →</span>
        </a>
        <a class="tile" routerLink="/academy" [queryParams]="{ status: 'draft' }">
          <span class="k">Черновиков академии</span>
          <span class="v tnum">{{ s.academy_drafts }}</span>
          <span class="d muted">Дописать →</span>
        </a>
        <a class="tile" routerLink="/journal" [queryParams]="{ status: 'published' }">
          <span class="k">Выпусков журнала</span>
          <span class="v tnum">{{ s.journal_published }}</span>
          <span class="d muted">Открыть журнал →</span>
        </a>
        <a class="tile" routerLink="/journal" [queryParams]="{ status: 'draft' }">
          <span class="k">Черновиков журнала</span>
          <span class="v tnum">{{ s.journal_drafts }}</span>
          <span class="d muted">Дописать →</span>
        </a>
      </div>

      <section class="panel">
        <div class="sec-head">
          <h2>Очередь модерации</h2>
          <a class="btn btn-ghost btn-sm" routerLink="/recipes" [queryParams]="{ status: 'moderation' }">
            Открыть все →
          </a>
        </div>

        @if (s.queue.length) {
          <div class="queue">
            @for (r of s.queue; track r.slug) {
              <div class="qrow">
                <div class="qinfo">
                  <a class="qname" [routerLink]="['/recipes', r.id]">{{ r.title }}</a>
                  <span class="muted small">{{ r.author?.name }}</span>
                </div>
                <div class="row">
                  <a class="btn btn-ghost btn-sm" [routerLink]="['/recipes', r.id]">Открыть</a>
                  @if (canPublish()) {
                    <button class="btn btn-green btn-sm" type="button" [disabled]="busy() === r.id"
                            (click)="publish(r.id)">Одобрить</button>
                  }
                </div>
              </div>
            }
          </div>
        } @else {
          <p class="muted">Очередь пуста — всё разобрано.</p>
        }
      </section>

      @if (!canPublish()) {
        <div class="note">
          Вы видите только свои материалы. Публикация и возврат на доработку доступны
          руководителю редакции.
        </div>
      }
    } @else if (error()) {
      <div class="empty"><h2>Не удалось загрузить данные</h2><p class="muted">{{ error() }}</p></div>
    } @else {
      <div class="loading"><div class="spinner"></div></div>
    }
  `,
    styles: [`
    .head { display: flex; flex-wrap: wrap; gap: 16px; align-items: center; justify-content: space-between; margin-bottom: 26px; }
    h1 { font-size: 30px; }
    .head p { margin: 4px 0 0; font-size: 14px; }
    .tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 18px; margin-bottom: 26px; }
    .tile { background: var(--surface); border: 1px solid var(--line); border-radius: 16px; padding: 20px; display: flex; flex-direction: column; gap: 6px; }
    a.tile:hover { border-color: var(--green); color: inherit; }
    .group { font-size: 22px; margin-bottom: 14px; }
    .k { font-size: 13px; color: var(--muted); }
    .v { font-size: 32px; font-weight: 800; letter-spacing: -0.02em; }
    .d { font-size: 13px; font-weight: 600; }
    .good { color: var(--green); }
    .warn { color: var(--brick-ink); }
    .panel { background: var(--surface); border: 1px solid var(--line); border-radius: 18px; padding: 22px; }
    .queue { display: flex; flex-direction: column; }
    .qrow {
      display: flex; flex-wrap: wrap; gap: 12px; align-items: center;
      justify-content: space-between; padding: 14px 0; border-bottom: 1px solid var(--line);
    }
    .qrow:last-child { border-bottom: 0; }
    .qinfo { min-width: 0; display: flex; flex-direction: column; gap: 2px; }
    .qname { font-weight: 600; }
    .small { font-size: 13px; }
    .note { margin-top: 20px; }
    .loading { display: grid; place-items: center; min-height: 40vh; }
  `]
})
export class DashboardPage implements OnInit {
  private api = inject(ApiService);
  private toasts = inject(ToastService);
  private auth = inject(AuthService);

  readonly stats = signal<AdminStats | null>(null);
  readonly error = signal<string | null>(null);
  readonly busy = signal<number | null>(null);

  readonly today = new Date().toLocaleDateString('ru-RU', {
    day: 'numeric', month: 'long', year: 'numeric',
  });

  ngOnInit(): void {
    this.load();
  }

  private load(): void {
    this.api.adminStats().subscribe({
      next: (res) => this.stats.set(res.data),
      error: (err) => this.error.set(errorText(err, 'Проверьте, запущен ли API.')),
    });
  }

  canPublish(): boolean {
    return this.auth.atLeast('editor_lead');
  }

  publish(id: number): void {
    this.busy.set(id);
    this.api.moveRecipe(id, 'publish').subscribe({
      next: () => {
        this.busy.set(null);
        this.toasts.show('Рецепт опубликован');
        this.load();
      },
      error: (err) => {
        this.busy.set(null);
        this.toasts.error(errorText(err));
      },
    });
  }
}
