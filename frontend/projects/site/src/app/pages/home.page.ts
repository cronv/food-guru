import { Component, OnInit, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';

import { ApiService } from '@shared/services/api.service';
import { AuthService } from '@shared/services/auth.service';
import { AcademyCard, ContestCard, HomeData, JournalIssueCard } from '@shared/models/api.models';
import { formatDate, pluralize } from '@shared/utils';
import { RecipeCardComponent } from '../components/recipe-card.component';
import { ReelStripComponent } from '../components/reel-strip.component';
import { SeoService } from '../services/seo.service';
import { siteSchemas } from '../services/structured-data';

@Component({
    selector: 'app-home',
    imports: [RouterLink, RecipeCardComponent, ReelStripComponent],
    template: `
    @if (data(); as d) {
      @if (d.hero; as hero) {
        <section class="wrap band hero">
          <div class="shot">
            @if (hero.cover) {
              <img [src]="hero.cover.url" [alt]="hero.cover.alt || hero.title">
            } @else {
              <div class="ph"></div>
            }
          </div>
          <div class="text">
            <span class="chip chip-g">Рецепт недели</span>
            <h1>{{ hero.title }}</h1>
            @if (hero.lead) { <p class="muted lead">{{ hero.lead }}</p> }
            <p class="muted small">
              @if (hero.time_minutes) { {{ hero.time_minutes }} минут · }
              {{ hero.servings }} порции
            </p>
            <div class="row">
              <a class="btn btn-primary" [routerLink]="['/recipes', hero.slug]">Открыть рецепт</a>
              <a class="btn btn-line" routerLink="/recipes">Все рецепты</a>
            </div>
          </div>
        </section>
      }

      <section class="wrap cats">
        <div class="row">
          @for (c of d.categories; track c.slug) {
            <a class="btn btn-ghost btn-sm" [routerLink]="['/categories', c.slug]">{{ c.title }}</a>
          }
        </div>
      </section>

      <section class="wrap band">
        <div class="sec-head">
          <h2>Популярное на этой неделе</h2>
          <a class="btn btn-ghost btn-sm" routerLink="/recipes">Смотреть все →</a>
        </div>
        <div class="grid">
          @for (r of d.popular; track r.slug) {
            <app-recipe-card [recipe]="r" />
          } @empty {
            <p class="muted">Рецептов пока нет.</p>
          }
        </div>
      </section>

      @if (d.reels.length) {
        <section class="wrap band">
          <div class="sec-head">
            <h2>Рилсы</h2>
            <a class="btn btn-ghost btn-sm" routerLink="/reels">Все ролики →</a>
          </div>
          <app-reel-strip [recipes]="d.reels" />
        </section>
      }

      @if (d.collections.length) {
        <section class="wrap band">
          <div class="sec-head">
            <h2>Самые популярные подборки рецептов</h2>
            <a class="btn btn-ghost btn-sm" routerLink="/collections">Все подборки →</a>
          </div>
          <div class="grid cols3">
            @for (c of d.collections; track c.slug) {
              <a class="tile" [routerLink]="['/collections', c.slug]">
                @if (c.cover) { <img [src]="c.cover.url" [alt]="''" loading="lazy"> }
                <span class="veil" aria-hidden="true"></span>
                <span class="count">{{ count(c.recipes_count) }}</span>
                <span class="name">{{ c.title }}</span>
              </a>
            }
          </div>
        </section>
      }

      @if (d.best.length) {
        <section class="wrap band">
          <div class="sec-head">
            <div>
              <h2>Самые лучшие рецепты</h2>
              <p class="muted sub">По оценкам читателей: доля лайков среди всех оценок.</p>
            </div>
            <a class="btn btn-ghost btn-sm" routerLink="/recipes">Все рецепты →</a>
          </div>
          <div class="grid six">
            @for (r of d.best; track r.slug) { <app-recipe-card [recipe]="r" /> }
          </div>
        </section>
      }

      @if (d.most_saved.length) {
        <section class="wrap band">
          <div class="sec-head">
            <div>
              <h2>Рейтинг по добавлению в рецептосохранялку</h2>
              <p class="muted sub">Что читатели чаще всего сохраняют себе, чтобы приготовить.</p>
            </div>
            @if (auth.isAuthenticated()) {
              <a class="btn btn-ghost btn-sm" routerLink="/cookbook">Моя рецептосохранялка →</a>
            }
          </div>
          <ol class="grid six ranked">
            @for (r of d.most_saved; track r.slug; let i = $index) {
              <li><span class="place tnum" aria-hidden="true">{{ i + 1 }}</span><app-recipe-card [recipe]="r" /></li>
            }
          </ol>
        </section>
      }

      @if (d.top_cooks.length) {
        <section class="wrap band">
          <div class="sec-head">
            <h2>Топ кулинаров</h2>
            <a class="btn btn-ghost btn-sm" routerLink="/rating">Таблица лидеров →</a>
          </div>
          <ol class="cooks">
            @for (c of d.top_cooks; track c.id; let i = $index) {
              <li class="cook">
                <span class="avatar" [class]="'avatar a' + (c.id % 4)" aria-hidden="true">{{ initials(c.name) }}</span>
                <span class="who">
                  <span class="nm">{{ c.name }}</span>
                  <span class="muted lvl">{{ c.rating_label }} · <span class="tnum">{{ c.rating_points }}</span> баллов</span>
                  <span class="muted lvl tnum">
                    @if (c.recipes_count) { {{ recipesLabel(c.recipes_count) }} · }
                    {{ reviewsLabel(c.reviews_count) }}
                  </span>
                </span>
                <span class="pos tnum" [attr.aria-label]="'Место ' + (i + 1)">{{ i + 1 }}</span>
              </li>
            }
          </ol>
        </section>
      }
    } @else if (error()) {
      <div class="wrap band empty">
        <h2>Не удалось загрузить рецепты</h2>
        <p class="muted">{{ error() }}</p>
      </div>
    } @else {
      <div class="wrap band loading"><div class="spinner"></div></div>
    }

    @if (contests().length) {
      <section class="wrap band">
        <div class="sec-head">
          <h2>Конкурсы</h2>
          <a class="btn btn-ghost btn-sm" routerLink="/contests">Все конкурсы →</a>
        </div>
        <div class="grid wide">
          @for (c of contests(); track c.slug) {
            <a class="card pick" [routerLink]="['/contests', c.slug]">
              <div class="shot">
                @if (c.cover) {
                  <img [src]="c.cover.url" [alt]="c.cover.alt || c.title" loading="lazy">
                } @else {
                  <div class="ph ph-green" aria-hidden="true">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1.3">
                      <path d="M7 4h10v5a5 5 0 0 1-10 0z" /><path d="M12 14v4" /><path d="M8.5 20h7" />
                    </svg>
                  </div>
                }
              </div>
              <div class="row tags">
                <span class="chip chip-g">{{ c.phase_label }}</span>
                <span class="chip">{{ c.kind_label }}</span>
              </div>
              <h3>{{ c.title }}</h3>
              @if (c.lead) { <p class="muted small">{{ c.lead }}</p> }
              @if (c.deadline_at) {
                <p class="muted small when">Ближайший срок — {{ date(c.deadline_at) }}</p>
              }
            </a>
          }
        </div>
      </section>
    }

    @if (sessions().length) {
      <section class="wrap band">
        <div class="sec-head">
          <h2>Кулинарная академия</h2>
          <a class="btn btn-ghost btn-sm" routerLink="/academy">Всё расписание →</a>
        </div>
        <div class="grid cols">
          @for (s of sessions(); track s.slug) {
            <a class="card pick" [routerLink]="['/academy', s.slug]">
              <div class="row tags">
                <span class="chip chip-g">{{ s.format_label }}</span>
                <span class="chip">{{ s.level_label }}</span>
                @if (s.is_live_now) { <span class="chip chip-b">Идёт сейчас</span> }
              </div>
              <h3>{{ s.title }}</h3>
              @if (s.host_name) { <p class="muted small">Ведёт {{ s.host_name }}</p> }
              @if (s.starts_at) {
                <p class="muted small when">{{ dateTime(s.starts_at) }}</p>
              }
            </a>
          }
        </div>
      </section>
    }

    @if (issues().length) {
      <section class="wrap band">
        <div class="sec-head">
          <h2>Журнал</h2>
          <a class="btn btn-ghost btn-sm" routerLink="/journal">Все выпуски →</a>
        </div>
        <div class="grid cols">
          @for (i of issues(); track i.slug) {
            <a class="card pick" [routerLink]="['/journal', i.slug]">
              <div class="row tags">
                <span class="chip chip-g">{{ i.period_label }}</span>
                <span class="chip">{{ i.period_range }}</span>
              </div>
              <h3>{{ i.title }}</h3>
              @if (i.lead) { <p class="muted small">{{ i.lead }}</p> }
              @if (pagesLabel(i.pages); as pages) {
                <p class="muted small when tnum">{{ pages }} в PDF</p>
              }
            </a>
          }
        </div>
      </section>
    }
  `,
    styles: [`
    .hero { display: flex; flex-wrap: wrap; gap: 32px; align-items: center; }
    .hero .shot { flex: 1 1 440px; min-width: 0; height: 380px; border-radius: 20px; overflow: hidden; background: var(--sunken); }
    .hero .shot img { width: 100%; height: 100%; object-fit: cover; }
    .ph { width: 100%; height: 100%; background: var(--sunken); }
    .hero .text { flex: 1 1 320px; min-width: 0; display: flex; flex-direction: column; gap: 16px; align-items: flex-start; }
    .hero h1 { font-size: clamp(30px, 4.4vw, 46px); line-height: 1.08; }
    .lead { margin: 0; font-size: 17px; }
    .small { margin: 0; font-size: 14px; }
    .cats { padding-bottom: 10px; }
    .cols { grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }
    .cols3 { grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); }
    /* Блоки по шесть — тремя колонками, чтобы не оставалось двух карточек во втором ряду. */
    .six { grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); }
    /* Плитка подборки: обложка, затемнение снизу и подпись поверх. */
    .tile {
      position: relative; overflow: hidden; isolation: isolate;
      display: flex; flex-direction: column; justify-content: flex-end;
      min-height: 200px; padding: 20px; border-radius: 18px; color: #fff; gap: 6px;
      background: var(--panel);
    }
    .tile img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; z-index: -2; transition: transform .25s; }
    .tile:hover img { transform: scale(1.04); }
    .veil { position: absolute; inset: 0; z-index: -1; background: linear-gradient(180deg, rgb(0 0 0 / 0) 30%, rgb(0 0 0 / .72)); }
    .tile:hover { color: #fff; }
    .count { font-size: 11px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; opacity: .9; }
    .name { font-family: var(--display); font-size: 22px; font-weight: 600; line-height: 1.2; }
    .sub { margin: 4px 0 0; font-size: 14px; }
    .ranked { list-style: none; margin: 0; padding: 0; }
    .ranked li { position: relative; }
    .place {
      position: absolute; top: 10px; left: 10px; z-index: 1; min-width: 30px; height: 30px; padding: 0 8px;
      border-radius: 999px; background: var(--ink); color: var(--bg);
      display: grid; place-items: center; font-weight: 700; font-size: 14px;
    }
    .cooks {
      list-style: none; margin: 0; padding: 0;
      display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 12px;
    }
    .cook {
      display: flex; align-items: center; gap: 14px; padding: 14px 16px;
      background: var(--surface); border: 1px solid var(--line); border-radius: 16px;
    }
    .avatar {
      flex: 0 0 auto; width: 52px; height: 52px; border-radius: 999px;
      display: grid; place-items: center; font-weight: 700; font-size: 18px; color: var(--on-accent);
    }
    .a0 { background: var(--green); }
    .a1 { background: var(--brick); }
    .a2 { background: var(--panel); color: var(--panel-ink); }
    .a3 { background: var(--green-ink); }
    .who { display: flex; flex-direction: column; min-width: 0; gap: 2px; }
    .nm { font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .lvl { font-size: 13px; }
    .pos { margin-left: auto; font-family: var(--display); font-size: 26px; font-weight: 600; color: var(--muted); }
    @media (prefers-reduced-motion: reduce) { .tile img { transition: none; } }
    .wide { grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); }
    .pick { gap: 10px; padding: 14px; }
    .pick .shot { height: 180px; border-radius: 14px; overflow: hidden; background: var(--sunken); }
    .pick .shot img { width: 100%; height: 100%; object-fit: cover; }
    .ph-green { background: var(--green-soft); color: var(--green-ink); display: grid; place-items: center; }
    .pick h3 { font-size: 21px; line-height: 1.25; padding: 0 2px; }
    .pick .small { padding: 0 2px; line-height: 1.55; }
    .pick .when { margin-top: auto; padding-top: 10px; border-top: 1px solid var(--line); font-weight: 600; }
    .loading { display: grid; place-items: center; min-height: 40vh; }
  `]
})
export class HomePage implements OnInit {
  private api = inject(ApiService);
  private seo = inject(SeoService);
  readonly auth = inject(AuthService);

  readonly data = signal<HomeData | null>(null);
  readonly error = signal<string | null>(null);
  readonly contests = signal<ContestCard[]>([]);
  readonly sessions = signal<AcademyCard[]>([]);
  readonly issues = signal<JournalIssueCard[]>([]);

  ngOnInit(): void {
    this.seo.apply({
      title: 'Food Guru — рецепты, которые получаются с первого раза',
      description: 'Пошаговые рецепты с пересчётом граммовок, видеорецепты и отзывы читателей с фотографиями.',
      path: '/',
    });
    const [site, org] = siteSchemas(this.seo);
    this.seo.jsonLd('website', site);
    this.seo.jsonLd('organization', org);

    this.api.home().subscribe({
      next: (res) => this.data.set(res.data),
      error: () => this.error.set('Проверьте, запущен ли API.'),
    });

    // Конкурсы, академия и журнал — отдельные запросы: если их нет, блоки просто не рисуются.
    this.api.contests({ scope: 'active' }).subscribe({
      next: (res) => this.contests.set(res.data.slice(0, 2)),
      error: () => this.contests.set([]),
    });

    this.api.academy({ scope: 'upcoming' }).subscribe({
      next: (res) => this.sessions.set(res.data.slice(0, 3)),
      error: () => this.sessions.set([]),
    });

    this.api.journal({}).subscribe({
      next: (res) => this.issues.set(res.data.slice(0, 3)),
      error: () => this.issues.set([]),
    });
  }

  count(n: number): string {
    return pluralize(n, 'рецепт', 'рецепта', 'рецептов');
  }

  recipesLabel(n: number): string {
    return pluralize(n, 'рецепт', 'рецепта', 'рецептов');
  }

  reviewsLabel(n: number): string {
    return pluralize(n, 'отзыв', 'отзыва', 'отзывов');
  }

  /** «Елена Смирнова» → «ЕС». */
  initials(name: string): string {
    return name.split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase();
  }

  pagesLabel(pages: number | null): string {
    if (!pages) return '';
    return pluralize(pages, 'страница', 'страницы', 'страниц');
  }

  date(iso: string | null): string {
    return formatDate(iso);
  }

  /** 3 ноября, 19:00 — в часовом поясе читателя. */
  dateTime(iso: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    return d.toLocaleString('ru-RU', {
      day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit',
    });
  }
}
