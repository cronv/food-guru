import { Component, OnInit, PLATFORM_ID, inject, signal, DOCUMENT } from '@angular/core';
import { isPlatformBrowser } from '@angular/common';
import { ActivatedRoute, RouterLink } from '@angular/router';

import { ApiService } from '@shared/services/api.service';
import { AuthService } from '@shared/services/auth.service';
import { SITE_URL } from '@shared/tokens';
import { Media, Recipe, RecipeCard, DIFFICULTY_LABELS } from '@shared/models/api.models';
import { errorText, pluralize, scaleAmount } from '@shared/utils';
import { GalleryComponent } from '../components/gallery.component';
import { MeasuresComponent } from '../components/measures.component';
import { MediaActionsComponent } from '../components/media-actions.component';
import { NutritionChartComponent } from '../components/nutrition-chart.component';
import { RecipeCardComponent } from '../components/recipe-card.component';
import { ReelComponent } from '../components/reel.component';
import { ReportFormComponent } from '../components/report-form.component';
import { StepTabsComponent } from '../components/step-tabs.component';
import { ReviewsComponent } from '../components/reviews.component';
import { HttpStatusService } from '../services/http-status.service';
import { SeoService } from '../services/seo.service';
import { recipeSchema } from '../services/structured-data';
import { ToastService } from '../services/toast.service';

@Component({
    selector: 'app-recipe',
    imports: [
        RouterLink, GalleryComponent, MediaActionsComponent, MeasuresComponent, NutritionChartComponent,
        RecipeCardComponent, ReelComponent, ReportFormComponent, ReviewsComponent, StepTabsComponent,
    ],
    template: `
    @if (recipe(); as r) {
      <article class="wrap band">
        <nav class="crumbs muted">
          <a routerLink="/">Главная</a> /
          @if (r.category) {
            <a [routerLink]="['/categories', r.category.slug]">{{ r.category.title }}</a> /
          }
          <span>{{ r.title }}</span>
        </nav>

        <div class="top">
          <div class="main">
            <div class="row">
              @if (r.category) { <span class="chip chip-g">{{ r.category.title }}</span> }
              @for (t of r.tags || []; track t.slug) { <span class="chip">{{ t.title }}</span> }
            </div>

            <h1>{{ r.title }}</h1>
            @if (r.lead) { <p class="lead muted">{{ r.lead }}</p> }

            <div class="facts">
              @if (r.time_minutes) {
                <div><span class="k">Время</span><span class="v">{{ r.time_minutes }} минут</span></div>
              }
              <div><span class="k">Сложность</span><span class="v">{{ difficulty(r) }}</span></div>
              @if (r.nutrition.calories) {
                <div><span class="k">Калорийность</span><span class="v">{{ r.nutrition.calories }} ккал / порция</span></div>
              }
              @if (r.author) {
                <div><span class="k">Автор</span><span class="v">{{ r.author.name }}</span></div>
              }
            </div>

            <app-gallery [items]="gallery()" [title]="r.title" />
            <!-- Под фото — только оценка и просмотры; избранное живёт в блоке справа. -->
            <app-media-actions [recipe]="r" [views]="views()" />
          </div>

          <!-- Правая колонка: действия с рецептом и видео — место рядом с фото иначе пустует. -->
          <aside class="side-col">
           <nav class="side" aria-label="Действия с рецептом">
            <button class="sbtn" type="button" (click)="scrollTo('related')">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/>
                <rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
              <span>Похожие рецепты</span>
              @if (related().length) { <span class="cnt tnum">{{ related().length }}</span> }
            </button>
            <button class="sbtn" type="button" (click)="scrollTo('comments')">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path d="M4 5h16v11H9l-5 4z"/></svg>
              <span>Комментарии</span>
              @if (r.reviews_count) { <span class="cnt tnum">{{ r.reviews_count }}</span> }
            </button>
            <button class="sbtn" type="button" (click)="reportDialog.showModal()">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path d="M5 21V4M5 4h11l-2 4 2 4H5"/></svg>
              <span>Пожаловаться</span>
            </button>
            <button class="sbtn fav" type="button" [class.on]="favorite()" [attr.aria-pressed]="favorite()"
                    (click)="toggleFavorite(r)">
              <svg width="18" height="18" viewBox="0 0 24 24" [attr.fill]="favorite() ? 'currentColor' : 'none'"
                   stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path d="M12 20.5s-8-4.6-8-10.4A4.6 4.6 0 0 1 12 7a4.6 4.6 0 0 1 8 3.1c0 5.8-8 10.4-8 10.4z"/></svg>
              <span>{{ favorite() ? 'В избранном' : 'В избранное' }}</span>
            </button>
            <button class="sbtn book" type="button" [class.on]="inCookbook()" [attr.aria-pressed]="inCookbook()"
                    [disabled]="cookbookBusy()" (click)="toggleCookbook(r)">
              <svg width="18" height="18" viewBox="0 0 24 24" [attr.fill]="inCookbook() ? 'currentColor' : 'none'"
                   stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path d="M6 3h12a1 1 0 0 1 1 1v17l-7-4-7 4V4a1 1 0 0 1 1-1z"/></svg>
              <span>{{ inCookbook() ? 'В рецептосохранялке' : 'В рецептосохранялку' }}</span>
              @if (saves() !== null) { <span class="cnt tnum" [attr.aria-label]="'Сохранили: ' + saves()">{{ saves() }}</span> }
            </button>
            @if (inCookbook()) {
              <p class="muted save-hint"><a routerLink="/cookbook">Открыть рецептосохранялку →</a></p>
            }

            <button class="sbtn" type="button" (click)="share.set(!share())" [attr.aria-expanded]="share()">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <circle cx="18" cy="5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="19" r="2.5"/>
                <path d="m8.2 10.8 7.6-4.4M8.2 13.2l7.6 4.4"/></svg>
              <span>Поделиться</span>
            </button>

            @if (share()) {
              <div class="share">
                <a class="btn tg" [href]="telegramUrl(r)" target="_blank" rel="noopener">
                  Отправить в Telegram
                </a>
                <label class="fl">Ссылка на рецепт
                  <input type="text" readonly [value]="pageUrl(r)" #link>
                </label>
                <button class="btn btn-ghost btn-sm" type="button" (click)="copy(r, link)">
                  {{ copied() ? 'Скопировано' : 'Скопировать ссылку' }}
                </button>
              </div>
            }
           </nav>

            @if (r.reel_url) {
              <section class="reel-block" aria-labelledby="reel-title">
                <h2 id="reel-title">Видеорецепт</h2>
                <p class="muted">Двадцать секунд: граммовки, температура и время на экране.</p>
                <app-reel [src]="r.reel_url" [title]="r.title" [poster]="r.cover?.url" />
              </section>
            }
          </aside>
        </div>

        <dialog #reportDialog class="dlg" aria-labelledby="report-title" tabindex="-1"
                (click)="$event.target === reportDialog && reportDialog.close()"
                (keydown.escape)="reportDialog.close()">
          <div class="dlg-in">
            <div class="dlg-head">
              <h2 id="report-title">Пожаловаться на рецепт</h2>
              <button class="close" type="button" aria-label="Закрыть" (click)="reportDialog.close()">✕</button>
            </div>
            <app-report-form [slug]="r.slug" />
          </div>
        </dialog>

        <div class="cook">
          <div class="ingredients">
            <h2>Ингредиенты</h2>

            <div class="servings">
              <span>Порций</span>
              <div class="stepper">
                <button class="btn btn-ghost" type="button" (click)="setServings(-1)"
                        aria-label="Уменьшить число порций">−</button>
                <span class="tnum value">{{ servings() }}</span>
                <button class="btn btn-ghost" type="button" (click)="setServings(1)"
                        aria-label="Увеличить число порций">+</button>
              </div>
            </div>

            <ul>
              @for (i of r.ingredients || []; track i.id) {
                <li>
                  <span>{{ i.name }}</span>
                  <span class="tnum amount">{{ amount(i.amount) }} {{ i.unit }}</span>
                </li>
              }
            </ul>

            <p class="hint">
              Количество пересчитывается на {{ servingsText() }}.
              @if (servings() !== r.servings) {
                <button class="reset" type="button" (click)="servings.set(r.servings)">
                  Вернуть {{ r.servings }} как в рецепте
                </button>
              }
            </p>

            <app-measures />
          </div>

          <div class="steps">
            <h2>Приготовление</h2>
            <ol>
              @for (s of r.steps || []; track s.id; let i = $index) {
                <li>
                  <span class="num">{{ i + 1 }}</span>
                  <div>
                    <p>{{ s.body }}</p>
                    @if (s.hint) {
                      <span class="chip step-hint">{{ s.hint }}</span>
                    }
                    @if (s.media) {
                      <img [src]="s.media.url" [alt]="'Шаг ' + (i + 1)" loading="lazy" class="step-shot">
                    }
                    <app-step-tabs [step]="s" [number]="i + 1" />
                  </div>
                </li>
              }
            </ol>

            @if (r.nutrition.protein || r.nutrition.fat || r.nutrition.carbs) {
              <section class="nutrition" aria-labelledby="nutrition-title">
                <h2 id="nutrition-title">Калорийность состава</h2>
                <app-nutrition-chart [protein]="r.nutrition.protein" [fat]="r.nutrition.fat"
                                     [carbs]="r.nutrition.carbs" [calories]="r.nutrition.calories"
                                     [servings]="servings()" />
              </section>
            }
          </div>
        </div>

        <section class="reviews-block" id="comments" tabindex="-1">
          <app-reviews [slug]="r.slug" [ratingAverage]="r.rating ?? null" [reviewsCount]="r.reviews_count ?? 0" />
        </section>

        <section class="related" id="related" tabindex="-1" aria-labelledby="related-title">
          <div class="sec-head"><h2 id="related-title">Похожие рецепты</h2></div>
          @if (related().length) {
            <div class="grid">
              @for (x of related(); track x.slug) { <app-recipe-card [recipe]="x" /> }
            </div>
          } @else {
            <p class="muted">Похожих рецептов пока нет.</p>
          }
        </section>
      </article>
    } @else if (error()) {
      <div class="wrap band empty">
        <h1>Рецепт не найден</h1>
        <p class="muted">{{ error() }}</p>
        <a class="btn btn-green" routerLink="/recipes">К каталогу</a>
      </div>
    } @else {
      <div class="wrap band loading"><div class="spinner"></div></div>
    }
  `,
    styles: [`
    .crumbs { font-size: 13px; margin-bottom: 14px; }
    .top { display: flex; flex-wrap: wrap; gap: 30px; align-items: flex-start; }
    .main { flex: 999 1 520px; min-width: 0; display: flex; flex-direction: column; gap: 18px; }
    h1 { font-size: clamp(30px, 4.4vw, 46px); line-height: 1.1; }
    .lead { margin: 0; font-size: 17px; max-width: 62ch; }
    .facts {
      display: flex; flex-wrap: wrap; gap: 24px;
      padding: 16px 0; border-top: 1px solid var(--line); border-bottom: 1px solid var(--line);
    }
    .facts .k { display: block; font-size: 11px; letter-spacing: .1em; text-transform: uppercase; color: var(--muted); }
    .facts .v { font-size: 16px; font-weight: 700; }
    .side {
      background: var(--surface); border: 1px solid var(--line); border-radius: 20px; padding: 16px;
      display: flex; flex-direction: column; gap: 8px;
    }
    .side h2 { font-family: var(--body); font-size: 17px; font-weight: 700; }
    .share {
      display: flex; flex-direction: column; gap: 10px; padding: 14px;
      border: 1px solid var(--line); border-radius: 14px; background: var(--sunken);
    }
    .tg { background: #2A8BBE; color: #fff; }
    .cook { display: flex; flex-wrap: wrap; gap: 30px; align-items: flex-start; margin-top: 44px; }
    .ingredients {
      flex: 1 1 300px; min-width: 0; background: var(--green-soft);
      border-radius: 20px; padding: 22px; display: flex; flex-direction: column; gap: 14px;
    }
    .ingredients h2, .steps h2 { font-size: 25px; }
    .ingredients h2 { color: var(--green-ink); }
    .servings {
      display: flex; align-items: center; justify-content: space-between; gap: 12px;
      background: var(--surface); border-radius: 14px; padding: 8px 12px; font-weight: 600;
    }
    .stepper { display: flex; align-items: center; gap: 6px; }
    .stepper .btn { width: 42px; min-height: 42px; padding: 0; }
    .stepper .value { min-width: 30px; text-align: center; font-size: 18px; font-weight: 700; }
    .ingredients ul { margin: 0; padding: 0; list-style: none; }
    .ingredients li {
      display: flex; justify-content: space-between; gap: 12px;
      padding: 10px 0; border-bottom: 1px solid rgba(128, 128, 128, .18);
    }
    .amount { font-weight: 700; color: var(--green); white-space: nowrap; }
    .hint { margin: 0; font-size: 13px; color: var(--green-ink); }
    .steps { flex: 999 1 500px; min-width: 0; display: flex; flex-direction: column; gap: 18px; }
    .steps ol { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 16px; }
    .steps li { display: flex; gap: 16px; align-items: flex-start; }
    .num {
      flex: 0 0 auto; width: 34px; height: 34px; border-radius: 999px;
      background: var(--green); color: var(--on-accent);
      display: grid; place-items: center; font-weight: 700;
    }
    .steps p { margin: 0; font-size: 16px; line-height: 1.65; }
    .step-shot { margin-top: 10px; border-radius: 12px; max-height: 260px; }
    .step-hint { margin-top: 8px; }
    .reel-block { display: flex; flex-direction: column; gap: 8px; }
    .reel-block h2 { margin: 0; font-size: 20px; }
    .reel-block p { margin: 0; font-size: 13px; }
    .reel-block app-reel { width: 100%; max-width: 340px; }
    .nutrition { margin-top: 10px; display: flex; flex-direction: column; gap: 12px; }
    .nutrition h2 { font-size: 22px; }
    .reset {
      border: 0; background: none; padding: 0; margin-left: 4px; font: inherit;
      color: var(--green-ink); text-decoration: underline; cursor: pointer;
    }
    .side-col { flex: 1 1 300px; min-width: 0; display: flex; flex-direction: column; gap: 22px; }
    .sbtn {
      display: flex; align-items: center; gap: 10px; width: 100%; min-height: 46px; padding: 0 14px;
      border-radius: 12px; border: 1px solid var(--line); background: var(--bg);
      font: inherit; font-weight: 600; color: var(--ink); cursor: pointer; text-align: left;
    }
    .sbtn:hover { border-color: var(--green); }
    .sbtn svg { flex: 0 0 auto; color: var(--muted); }
    .sbtn.fav.on { background: var(--brick-soft); border-color: var(--brick); color: var(--brick-ink); }
    .sbtn.fav.on svg { color: currentColor; }
    .sbtn.book.on { background: var(--green-soft); border-color: var(--green); color: var(--green-ink); }
    .sbtn.book.on svg { color: currentColor; }
    .sbtn:disabled { opacity: .6; cursor: progress; }
    .cnt {
      margin-left: auto; min-width: 24px; height: 24px; padding: 0 7px; border-radius: 999px; font-size: 12px;
      background: var(--sunken); color: var(--ink); display: inline-grid; place-items: center;
    }
    .save-hint { margin: -2px 4px 2px; font-size: 12px; }
    .reviews-block, .related { margin-top: 48px; scroll-margin-top: 90px; }
    .reviews-block:focus, .related:focus { outline: none; }
    .dlg {
      width: min(560px, calc(100vw - 32px)); max-height: calc(100vh - 32px); padding: 0;
      border: 0; border-radius: 20px; background: var(--surface); color: var(--ink);
    }
    .dlg::backdrop { background: rgb(0 0 0 / .45); }
    .dlg-in { padding: 22px; display: flex; flex-direction: column; gap: 14px; }
    .dlg-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
    .dlg-head h2 { font-size: 22px; margin: 0; }
    .close {
      width: 40px; height: 40px; border-radius: 999px; border: 1px solid var(--line);
      background: var(--bg); color: var(--ink); cursor: pointer; font-size: 16px;
    }
    .loading { display: grid; place-items: center; min-height: 50vh; }
  `]
})
export class RecipePage implements OnInit {
  private api = inject(ApiService);
  private route = inject(ActivatedRoute);
  private seo = inject(SeoService);
  private status = inject(HttpStatusService);
  private toasts = inject(ToastService);
  private siteUrl = inject(SITE_URL);
  auth = inject(AuthService);

  readonly recipe = signal<Recipe | null>(null);
  readonly related = signal<RecipeCard[]>([]);
  readonly error = signal<string | null>(null);
  readonly servings = signal(4);
  readonly favorite = signal(false);
  readonly share = signal(false);
  readonly copied = signal(false);
  readonly views = signal<number | undefined>(undefined);
  /** Сколько человек сохранили в рецептосохранялку; обновляется ответом сервера. */
  readonly saves = signal<number | null>(null);
  readonly inCookbook = signal(false);
  readonly cookbookBusy = signal(false);
  private platformId = inject(PLATFORM_ID);
  private document = inject(DOCUMENT);

  /** Кнопки справа ведут к разделам внизу страницы; фокус — чтобы скринридер тоже «переехал». */
  scrollTo(id: string): void {
    const el = this.document.getElementById(id);
    if (!el) return;
    const smooth = !this.document.defaultView?.matchMedia('(prefers-reduced-motion: reduce)').matches;
    el.scrollIntoView({ behavior: smooth ? 'smooth' : 'auto', block: 'start' });
    el.focus({ preventScroll: true });
  }

  servingsText(): string {
    return pluralize(this.servings(), 'порцию', 'порции', 'порций');
  }

  ngOnInit(): void {
    this.route.paramMap.subscribe((params) => {
      const slug = params.get('slug');
      if (!slug) return;
      this.load(slug);
    });
  }

  private load(slug: string): void {
    this.recipe.set(null);
    this.error.set(null);
    this.views.set(undefined);
    this.saves.set(null);

    this.api.recipe(slug).subscribe({
      next: (res) => {
        const r = res.data;
        this.recipe.set(r);

        // Просмотр отмечает только браузер: сервер рендера — не зритель.
        // Ошибка здесь не важна для читателя, поэтому молча.
        if (isPlatformBrowser(this.platformId)) {
          this.api.recordView(r.slug).subscribe({
            next: (v) => this.views.set(v.data.views_count),
            error: () => undefined,
          });
        }
        this.servings.set(r.servings || 4);
        this.favorite.set(r.is_favorite === true);
        this.saves.set(r.saves_count ?? null);
        this.inCookbook.set(r.in_cookbook === true);

        this.seo.apply({
          title: r.title,
          description: r.og_description || r.lead,
          image: r.cover?.url,
          path: `/recipes/${r.slug}`,
          type: 'article',
        });
        this.seo.breadcrumbs([
          { name: 'Главная', path: '/' },
          ...(r.category ? [{ name: r.category.title, path: `/categories/${r.category.slug}` }] : [{ name: 'Рецепты', path: '/recipes' }]),
          { name: r.title, path: `/recipes/${r.slug}` },
        ]);
        this.seo.jsonLd('recipe', recipeSchema(this.seo, r));
      },
      error: (err) => {
        this.error.set('Возможно, рецепт сняли с публикации.');
        this.status.fromError(err);
      },
    });

    this.api.related(slug).subscribe({
      next: (res) => this.related.set(res.data),
      error: () => this.related.set([]),
    });
  }

  gallery(): Media[] {
    const r = this.recipe();
    if (!r) return [];
    const list: Media[] = [];
    if (r.cover) list.push(r.cover);
    for (const m of r.gallery || []) {
      if (!list.some((x) => x.id === m.id)) list.push(m);
    }
    return list;
  }

  difficulty(r: Recipe): string {
    return DIFFICULTY_LABELS[r.difficulty] ?? '';
  }

  amount(value: number | null): string {
    return scaleAmount(value, this.servings());
  }

  setServings(step: number): void {
    this.servings.set(Math.min(24, Math.max(1, this.servings() + step)));
  }

  pageUrl(r: Recipe): string {
    return `${this.siteUrl}/recipes/${r.slug}`;
  }

  telegramUrl(r: Recipe): string {
    const url = encodeURIComponent(this.pageUrl(r));
    const text = encodeURIComponent(`${r.title} — рецепт на «Food Guru»`);
    return `https://t.me/share/url?url=${url}&text=${text}`;
  }

  copy(r: Recipe, input: HTMLInputElement): void {
    const text = this.pageUrl(r);
    if (navigator.clipboard?.writeText) {
      navigator.clipboard.writeText(text).then(
        () => {
          this.copied.set(true);
          this.toasts.show('Ссылка скопирована');
        },
        () => {
          input.select();
          this.toasts.show('Скопируйте ссылку вручную — выделено');
        },
      );
    } else {
      input.select();
      this.toasts.show('Скопируйте ссылку вручную — выделено');
    }
  }

  toggleFavorite(r: Recipe): void {
    if (!this.auth.isAuthenticated()) {
      this.toasts.show('Войдите, чтобы сохранять рецепты');
      return;
    }
    if (!this.auth.isVerified()) {
      this.toasts.show('Сначала подтвердите адрес почты');
      return;
    }

    const next = !this.favorite();
    this.favorite.set(next);

    const request = next ? this.api.addFavorite(r.id) : this.api.removeFavorite(r.id);
    request.subscribe({
      next: () => this.toasts.show(next ? 'Добавлено в избранное' : 'Убрано из избранного'),
      error: (err) => {
        this.favorite.set(!next);
        this.toasts.error(errorText(err));
      },
    });
  }

  toggleCookbook(r: Recipe): void {
    if (!this.auth.isAuthenticated()) {
      this.toasts.show('Войдите, чтобы вести рецептосохранялку');
      return;
    }
    if (!this.auth.isVerified()) {
      this.toasts.show('Сначала подтвердите адрес почты');
      return;
    }

    const next = !this.inCookbook();
    this.inCookbook.set(next);
    this.cookbookBusy.set(true);

    this.api.toggleCookbook(r.id, next).subscribe({
      next: (res) => {
        this.inCookbook.set(res.data.in_cookbook);
        this.saves.set(res.data.saves_count);
        this.cookbookBusy.set(false);
        this.toasts.show(next ? 'Сохранено в рецептосохранялку' : 'Убрано из рецептосохранялки');
      },
      error: (err) => {
        this.inCookbook.set(!next);
        this.cookbookBusy.set(false);
        this.toasts.error(errorText(err));
      },
    });
  }
}
