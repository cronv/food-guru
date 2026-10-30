import { Component, OnInit, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';

import { ApiService } from '@shared/services/api.service';
import { AuthService } from '@shared/services/auth.service';
import { LeaderboardRow, MyRating, RatingLevelInfo } from '@shared/models/api.models';
import { pluralize } from '@shared/utils';
import { SeoService } from '../services/seo.service';

@Component({
    selector: 'app-rating',
    imports: [RouterLink],
    template: `
    <div class="wrap band">
      <h1>Таблица лидеров</h1>
      <p class="muted sub">
        Баллы начисляются за заходы на сайт, серию заходов, опубликованные рецепты, отзывы
        и фотоотчёты.
      </p>

      @if (my(); as m) {
        <section class="mine">
          <div class="sec-head"><h2>Ваш рейтинг</h2></div>

          <div class="cards">
            <div class="box">
              <span class="k">Баллы</span>
              <span class="v tnum">{{ m.points }}</span>
            </div>
            <div class="box">
              <span class="k">Уровень</span>
              <span class="v">{{ m.level_label }}</span>
            </div>
            <div class="box">
              <span class="k">Серия заходов</span>
              <span class="v tnum">{{ daysLabel(m.streak_days) }}</span>
              <span class="muted extra">Лучшая серия: {{ daysLabel(m.streak_best) }}</span>
            </div>
            <div class="box">
              <span class="k">Позиция</span>
              <span class="v tnum">{{ m.position !== null ? '№ ' + m.position : 'пока нет' }}</span>
              @if (m.position === null) {
                <span class="muted extra">В таблицу попадают читатели с баллами выше нуля.</span>
              }
            </div>
          </div>

          <div class="progress">
            <div class="plabel">
              @if (m.next_level_label && m.points_to_next !== null) {
                <span>До уровня «{{ m.next_level_label }}» осталось
                  <strong class="tnum">{{ pointsLabel(m.points_to_next) }}</strong></span>
              } @else {
                <span>Это верхний уровень шкалы</span>
              }
              <span class="muted tnum">{{ percent(m.progress) }}%</span>
            </div>
            <div class="track" role="progressbar" [attr.aria-valuenow]="percent(m.progress)"
                 aria-valuemin="0" aria-valuemax="100"
                 [attr.aria-label]="'Прогресс до следующего уровня: ' + percent(m.progress) + '%'">
              <div class="fill" [style.width.%]="percent(m.progress)"></div>
            </div>
          </div>

          @if (m.breakdown.length) {
            <div class="breakdown">
              <h3>Из чего сложились баллы</h3>
              <ul>
                @for (b of m.breakdown; track b.source) {
                  <li>
                    <span class="name">{{ b.label }}</span>
                    <span class="muted howmany tnum">{{ b.count }} {{ b.unit }}</span>
                    <span class="pts tnum">{{ b.points }}</span>
                  </li>
                }
              </ul>
            </div>
          }
        </section>
      } @else if (auth.loaded() && !auth.isAuthenticated()) {
        <div class="note guest">
          Заведите аккаунт — и ваши заходы, рецепты и отзывы начнут приносить баллы, а имя
          появится в таблице.
          <a class="btn btn-green btn-sm cta" routerLink="/register">Создать аккаунт</a>
        </div>
      }

      <section class="board">
        <div class="sec-head">
          <h2>Лидеры</h2>
          @if (total() !== null) {
            <span class="muted tnum">{{ peopleLabel(total() ?? 0) }} в таблице</span>
          }
        </div>

        @if (levels().length) {
          <div class="scale">
            <span class="muted label">Шкала уровней</span>
            <div class="row">
              @for (l of levels(); track l.value) {
                <span class="chip" [class.chip-g]="l.value === my()?.level"
                      [attr.aria-current]="l.value === my()?.level ? 'true' : null">
                  {{ l.label }} · от {{ l.threshold }}
                </span>
              }
            </div>
          </div>
        }

        <div class="filters" role="group" aria-label="Фильтр по уровню">
          <button class="toggle" type="button" [class.on]="level === ''"
                  [attr.aria-pressed]="level === ''" (click)="setLevel('')">
            Все уровни
          </button>
          @for (l of levels(); track l.value) {
            <button class="toggle" type="button" [class.on]="level === l.value"
                    [attr.aria-pressed]="level === l.value" (click)="setLevel(l.value)">
              {{ l.label }}
            </button>
          }
        </div>

        @if (loading() && !rows().length) {
          <div class="loading"><div class="spinner"></div></div>
        } @else if (rows().length) {
          <div class="scroll">
            <table>
              <caption class="sr">Читатели по числу баллов</caption>
              <thead>
                <tr>
                  <th scope="col">Место</th>
                  <th scope="col">Читатель</th>
                  <th scope="col">Уровень</th>
                  <th scope="col">Баллы</th>
                  <th scope="col">Серия</th>
                </tr>
              </thead>
              <tbody>
                @for (r of rows(); track r.id) {
                  <tr [class.me]="r.is_me">
                    <td class="tnum">{{ r.position ?? '—' }}</td>
                    <td>
                      {{ r.name }}
                      @if (r.is_me) { <span class="chip chip-g you">это вы</span> }
                    </td>
                    <td>{{ r.rating_label }}</td>
                    <td class="tnum strong">{{ r.rating_points }}</td>
                    <td class="tnum">{{ daysLabel(r.streak_days) }}</td>
                  </tr>
                }
              </tbody>
            </table>
          </div>

          @if (page() < lastPage()) {
            <div class="more">
              <button class="btn btn-ghost" type="button" [disabled]="loading()"
                      (click)="loadMore()">
                {{ loading() ? 'Загружаем…' : 'Показать ещё' }}
              </button>
            </div>
          }
        } @else if (failed()) {
          <div class="empty">
            <h3>Не удалось загрузить таблицу</h3>
            <p class="muted">Похоже, связь с сервером пропала. Обновите страницу через минуту.</p>
            <button class="btn btn-green" type="button" (click)="reload()">Попробовать снова</button>
          </div>
        } @else {
          <div class="empty">
            <h3>{{ level ? 'На этом уровне пока никого нет' : 'В таблице пока никого нет' }}</h3>
            <p class="muted">
              {{ level
                ? 'Выберите другой уровень — читатели поднимаются по шкале по мере набора баллов.'
                : 'Баллы начисляются за заходы, рецепты и отзывы. Первые читатели появятся здесь сразу после начисления.' }}
            </p>
            @if (level) {
              <button class="btn btn-ghost" type="button" (click)="setLevel('')">Показать все уровни</button>
            }
          </div>
        }
      </section>
    </div>
  `,
    styles: [`
    h1 { font-size: clamp(28px, 4vw, 40px); }
    .sub { margin: 6px 0 26px; max-width: 64ch; }
    .mine { margin-bottom: 40px; }
    .cards { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); }
    .box {
      background: var(--surface); border: 1px solid var(--line); border-radius: 16px;
      padding: 16px; display: flex; flex-direction: column; gap: 4px;
    }
    .box .k { font-size: 11px; letter-spacing: .1em; text-transform: uppercase; color: var(--muted); }
    .box .v { font-size: 22px; font-weight: 700; }
    .box .extra { font-size: 12px; line-height: 1.45; }
    .progress { margin-top: 18px; display: flex; flex-direction: column; gap: 8px; }
    .plabel { display: flex; flex-wrap: wrap; gap: 10px; justify-content: space-between; font-size: 14px; }
    .track { height: 10px; border-radius: 999px; background: var(--sunken); overflow: hidden; }
    .fill { height: 100%; background: var(--green); border-radius: 999px; }
    .breakdown { margin-top: 24px; }
    .breakdown h3 { font-family: var(--body); font-size: 17px; font-weight: 700; margin-bottom: 10px; }
    .breakdown ul { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 8px; max-width: 560px; }
    .breakdown li {
      display: flex; align-items: baseline; gap: 12px;
      padding-bottom: 8px; border-bottom: 1px solid var(--line);
    }
    .breakdown .name { flex: 1 1 auto; min-width: 0; }
    .breakdown .howmany { font-size: 13px; }
    .breakdown .pts { font-weight: 700; color: var(--green); }
    .guest { margin-bottom: 36px; }
    .cta { margin-top: 10px; }
    .scale { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; }
    .scale .label { font-size: 13px; font-weight: 700; }
    .filters { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 18px; }
    .toggle {
      min-height: 44px; padding: 0 16px; border-radius: 999px; border: 1px solid var(--line);
      background: var(--surface); color: var(--muted); font-weight: 600;
    }
    .toggle.on { background: var(--green); border-color: var(--green); color: var(--on-accent); }
    .sr {
      position: absolute; width: 1px; height: 1px; overflow: hidden;
      clip-path: inset(50%); white-space: nowrap;
    }
    tr.me { background: var(--green-soft); }
    tr.me td { color: var(--green-ink); font-weight: 600; }
    .you { margin-left: 8px; }
    .strong { font-weight: 700; }
    .loading { display: grid; place-items: center; min-height: 200px; }
    .more { display: flex; justify-content: center; margin-top: 24px; }
  `]
})
export class RatingPage implements OnInit {
  /** Номер запроса: ответ, отставший от нового отбора, игнорируем,
   *  иначе он перезапишет свежий список устаревшим. */
  private loadSeq = 0;

  private api = inject(ApiService);
  private seo = inject(SeoService);
  auth = inject(AuthService);

  readonly my = signal<MyRating | null>(null);
  readonly rows = signal<LeaderboardRow[]>([]);
  readonly levels = signal<RatingLevelInfo[]>([]);
  readonly total = signal<number | null>(null);
  readonly page = signal(1);
  readonly lastPage = signal(1);
  readonly loading = signal(true);
  readonly failed = signal(false);

  level = '';

  ngOnInit(): void {
    this.seo.apply({
      title: 'Таблица лидеров',
      description: 'Баллы читателей «Food Guru»: уровни, серии заходов и позиции в таблице.',
      path: '/rating',
    });

    // Свой рейтинг запрашиваем сразу: для гостя ответ будет 401, и блок просто не нарисуется.
    this.api.myRating().subscribe({
      next: (res) => this.my.set(res.data),
      error: () => this.my.set(null),
    });

    this.load(1);
  }

  private load(page: number): void {
    this.loading.set(true);
    this.failed.set(false);

    const seq = ++this.loadSeq;

    this.api.leaderboard(this.level || undefined, page).subscribe({
      next: (res) => {
        if (seq !== this.loadSeq) return;

        this.rows.set(page === 1 ? res.data : [...this.rows(), ...res.data]);
        this.total.set(res.meta.total);
        this.page.set(res.meta.current_page);
        this.lastPage.set(res.meta.last_page);
        // Пороги уровней приходят с сервера, чтобы не держать их в двух местах.
        this.levels.set(res.meta.levels);
        this.loading.set(false);
      },
      error: () => {
        if (seq !== this.loadSeq) return;

        if (page === 1) {
          this.rows.set([]);
          this.total.set(0);
        }
        this.failed.set(true);
        this.loading.set(false);
      },
    });
  }

  setLevel(level: string): void {
    if (this.level === level) return;
    this.level = level;
    this.load(1);
  }

  loadMore(): void {
    this.load(this.page() + 1);
  }

  reload(): void {
    this.load(1);
  }

  percent(progress: number): number {
    return Math.round(Math.min(1, Math.max(0, progress)) * 100);
  }

  pointsLabel(n: number): string {
    return pluralize(n, 'балл', 'балла', 'баллов');
  }

  daysLabel(n: number): string {
    return pluralize(n, 'день', 'дня', 'дней');
  }

  peopleLabel(n: number): string {
    return pluralize(n, 'читатель', 'читателя', 'читателей');
  }
}
