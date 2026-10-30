import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';

import { Category, MyRecipeRow } from '@shared/models/api.models';
import { ApiService } from '@shared/services/api.service';
import { AuthService } from '@shared/services/auth.service';
import { errorText } from '@shared/utils';
import { BreadcrumbsComponent } from '../components/breadcrumbs.component';
import { SeoService } from '../services/seo.service';
import { ToastService } from '../services/toast.service';

interface IngredientRow { name: string; amount: string; unit: string }
interface StepRow { body: string; hint: string; tip: string; substitution: string; media_id: number | null; media_url: string | null }

const UNITS = ['г', 'кг', 'мл', 'л', 'шт', 'ч. л.', 'ст. л.', 'стакан', 'щепотка', 'по вкусу'];

/**
 * Редактор рецепта читателя. Сохраняется черновиком; «Отправить на модерацию»
 * сначала сохраняет, потом отправляет. Чек-лист справа показывает, чего не хватает,
 * до того как сервер скажет «нельзя».
 */
@Component({
    selector: 'app-my-recipe-edit',
    imports: [FormsModule, RouterLink, BreadcrumbsComponent],
    template: `
    <div class="wrap band">
      <app-breadcrumbs [items]="[
        { label: 'Главная', link: '/' },
        { label: 'Личный кабинет', link: '/account' },
        { label: 'Мои рецепты', link: '/account/recipes' },
        { label: id() ? (title || 'Рецепт') : 'Новый рецепт' }
      ]" />

      @if (!auth.isVerified()) {
        <div class="note-warn">Подтвердите почту, чтобы добавлять рецепты.</div>
      } @else if (loading()) {
        <div class="spinner"></div>
      } @else {
        <div class="head">
          <h1>{{ id() ? 'Редактирование рецепта' : 'Новый рецепт' }}</h1>
          @if (row(); as r) { <span class="chip" [class.chip-b]="r.status === 'rejected'" [class.chip-g]="r.status === 'published'">{{ r.status_label }}</span> }
        </div>

        @if (row()?.moderation_note) {
          <div class="note-warn block"><strong>Редакция просит поправить:</strong> {{ row()!.moderation_note }}</div>
        }
        @if (row() && !row()!.can_edit) {
          <div class="note block">
            @if (row()!.status === 'moderation') {
              Рецепт на проверке у редакции — обычно это до двух рабочих дней. Чтобы что-то поправить, заберите его с проверки.
              <button class="btn btn-ghost btn-sm" type="button" [disabled]="busy()" (click)="withdraw()">Забрать с проверки</button>
            } @else if (row()!.status === 'published') {
              Рецепт опубликован. <a [routerLink]="['/recipes', row()!.slug]">Открыть на сайте</a>. Изменить его может только редакция.
            } @else {
              Этот рецепт сейчас нельзя изменить.
            }
          </div>
        }

        <div class="layout">
          <form class="form" (submit)="$event.preventDefault()" (input)="touch()" (change)="touch()"
                [class.locked]="row() && !row()!.can_edit">
            <fieldset [disabled]="!!row() && !row()!.can_edit">
              <section class="card">
                <h2>1. Основное</h2>
                <label class="fl" for="r-title">Название
                  <input id="r-title" name="title" [(ngModel)]="title" maxlength="200" placeholder="Например, Блины на молоке">
                </label>
                <label class="fl" for="r-lead">Пара слов о блюде
                  <textarea id="r-lead" name="lead" rows="3" maxlength="600" [(ngModel)]="lead"
                            placeholder="Чем блюдо хорошо, в чём секрет — то, что увидят под названием"></textarea>
                  <span class="muted weak">Не короче 20 символов.</span>
                </label>
                <div class="grid3">
                  <label class="fl" for="r-cat">Категория
                    <select id="r-cat" name="cat" [(ngModel)]="categoryId">
                      <option [ngValue]="null">Не выбрана</option>
                      @for (c of categories(); track c.id) { <option [ngValue]="c.id">{{ c.title }}</option> }
                    </select>
                  </label>
                  <label class="fl" for="r-time">Время, мин
                    <input id="r-time" name="time" type="number" inputmode="numeric" min="1" max="1440" [(ngModel)]="time">
                  </label>
                  <label class="fl" for="r-serv">Порций
                    <input id="r-serv" name="serv" type="number" inputmode="numeric" min="1" max="50" [(ngModel)]="servings">
                  </label>
                  <label class="fl" for="r-diff">Сложность
                    <select id="r-diff" name="diff" [(ngModel)]="difficulty">
                      <option value="easy">Просто</option><option value="medium">Средне</option><option value="hard">Сложно</option>
                    </select>
                  </label>
                </div>
                <div class="row checks">
                  <label class="opt"><input type="checkbox" name="veg" [(ngModel)]="vegetarian"> Вегетарианское</label>
                  <label class="opt"><input type="checkbox" name="gf" [(ngModel)]="glutenFree"> Без глютена</label>
                </div>
              </section>

              <section class="card">
                <h2>2. Фото готового блюда</h2>
                <div class="cover">
                  @if (coverUrl()) { <img [src]="coverUrl()" alt="Фото блюда"> } @else { <span class="muted">Фото пока нет</span> }
                </div>
                <label class="btn btn-line upload">
                  {{ uploading() === 'cover' ? 'Загружаем…' : (coverUrl() ? 'Заменить фото' : 'Загрузить фото') }}
                  <input type="file" accept="image/jpeg,image/png,image/webp" (change)="upload($event, 'cover')" hidden>
                </label>
                <p class="muted weak">JPG, PNG или WebP до 8 МБ. Лучше при дневном свете и сверху или под углом 45°.</p>
              </section>

              <section class="card">
                <h2>3. Ингредиенты</h2>
                <p class="muted weak">Количество — в граммах или миллилитрах: так пересчёт порций будет точным.</p>
                @for (i of ingredients; track $index; let n = $index) {
                  <div class="ing">
                    <input [name]="'iname' + n" [(ngModel)]="i.name" placeholder="Мука" aria-label="Ингредиент">
                    <input [name]="'iamt' + n" [(ngModel)]="i.amount" inputmode="decimal" placeholder="200" aria-label="Количество">
                    <select [name]="'iunit' + n" [(ngModel)]="i.unit" aria-label="Единица">
                      @for (u of units; track u) { <option [value]="u">{{ u }}</option> }
                    </select>
                    <button type="button" class="x" (click)="ingredients.splice(n, 1); touch()" aria-label="Убрать ингредиент">✕</button>
                  </div>
                }
                <button type="button" class="btn btn-ghost btn-sm" (click)="ingredients.push({ name: '', amount: '', unit: 'г' }); touch()">+ Ингредиент</button>
              </section>

              <section class="card">
                <h2>4. Шаги</h2>
                @for (s of steps; track $index; let n = $index) {
                  <div class="step">
                    <div class="step-head">
                      <span class="num">{{ n + 1 }}</span>
                      <div class="moves">
                        <button type="button" [disabled]="n === 0" (click)="move(n, -1)" aria-label="Выше">↑</button>
                        <button type="button" [disabled]="n === steps.length - 1" (click)="move(n, 1)" aria-label="Ниже">↓</button>
                        <button type="button" (click)="steps.splice(n, 1); touch()" aria-label="Убрать шаг">✕</button>
                      </div>
                    </div>
                    <textarea [name]="'sbody' + n" rows="3" [(ngModel)]="s.body" maxlength="2000"
                              placeholder="Что сделать на этом шаге" [attr.aria-label]="'Шаг ' + (n + 1)"></textarea>
                    <input [name]="'shint' + n" [(ngModel)]="s.hint" maxlength="160" placeholder="Подсказка: 180 °C · 20 минут (необязательно)">
                    <details>
                      <summary>Совет и замена ингредиентов</summary>
                      <textarea [name]="'stip' + n" rows="2" [(ngModel)]="s.tip" maxlength="1000" placeholder="Совет к шагу"></textarea>
                      <textarea [name]="'ssub' + n" rows="2" [(ngModel)]="s.substitution" maxlength="1000" placeholder="Чем заменить, если чего-то нет"></textarea>
                    </details>
                    <div class="step-photo">
                      @if (s.media_url) { <img [src]="s.media_url" alt=""> }
                      <label class="btn btn-ghost btn-sm">
                        {{ uploading() === 'step' + n ? 'Загружаем…' : (s.media_url ? 'Заменить фото шага' : 'Фото шага') }}
                        <input type="file" accept="image/jpeg,image/png,image/webp" (change)="upload($event, 'step' + n)" hidden>
                      </label>
                    </div>
                  </div>
                }
                <button type="button" class="btn btn-ghost btn-sm" (click)="steps.push(emptyStep()); touch()">+ Шаг</button>
              </section>
            </fieldset>
          </form>

          <aside class="side">
            <div class="card sticky">
              <h2>Перед отправкой</h2>
              <ul class="check">
                @for (c of checklist(); track c.label) {
                  <li [class.ok]="c.ok"><span aria-hidden="true">{{ c.ok ? '✓' : '○' }}</span>{{ c.label }}</li>
                }
              </ul>
              @if (errors().length) {
                <ul class="err" role="alert">@for (e of errors(); track e) { <li>{{ e }}</li> }</ul>
              }
              @if (!row() || row()!.can_edit) {
                <button class="btn btn-green" type="button" [disabled]="busy()" (click)="save(true)">
                  {{ busy() ? 'Сохраняем…' : 'Отправить на модерацию' }}
                </button>
                <button class="btn btn-ghost" type="button" [disabled]="busy()" (click)="save(false)">Сохранить черновик</button>
              }
              <p class="muted weak">Редакция проверит граммовки и шаги. После публикации вы получите письмо и 25 баллов рейтинга.</p>
              @if (id() && row()?.can_edit) {
                @if (confirmDelete()) {
                  <div class="row">
                    <button class="btn btn-sm danger" type="button" (click)="remove()">Да, удалить</button>
                    <button class="btn btn-ghost btn-sm" type="button" (click)="confirmDelete.set(false)">Нет</button>
                  </div>
                } @else {
                  <button class="link-danger" type="button" (click)="confirmDelete.set(true)">Удалить черновик</button>
                }
              }
            </div>
          </aside>
        </div>
      }
    </div>
  `,
    styles: [`
    .head { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-bottom: 14px; }
    h1 { font-size: clamp(26px, 4vw, 36px); }
    .block { margin-bottom: 16px; }
    .note { padding: 12px 14px; border-radius: 12px; background: var(--surface); border: 1px solid var(--line); display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
    .layout { display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: 24px; align-items: start; }
    @media (max-width: 900px) { .layout { grid-template-columns: minmax(0, 1fr); } .sticky { position: static !important; } }
    fieldset { border: 0; margin: 0; padding: 0; min-width: 0; display: flex; flex-direction: column; gap: 16px; }
    .locked { opacity: .75; }
    .card { padding: 20px; border-radius: 18px; background: var(--surface); border: 1px solid var(--line); display: flex; flex-direction: column; gap: 12px; }
    .card h2 { font-family: var(--body); font-size: 18px; font-weight: 700; margin: 0; }
    .grid3 { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
    .checks { gap: 18px; }
    .opt { display: flex; align-items: center; gap: 8px; min-height: 40px; cursor: pointer; }
    .weak { font-weight: 400; font-size: 13px; margin: 0; }
    .cover { aspect-ratio: 4 / 3; max-width: 420px; border-radius: 14px; overflow: hidden; background: var(--sunken); display: grid; place-items: center; }
    .cover img { width: 100%; height: 100%; object-fit: cover; }
    .upload { align-self: flex-start; cursor: pointer; }
    .ing { display: grid; grid-template-columns: minmax(0, 1fr) 90px 110px 36px; gap: 8px; align-items: center; }
    @media (max-width: 520px) { .ing { grid-template-columns: minmax(0, 1fr) 80px 36px; } .ing select { grid-column: 1 / 3; grid-row: 2; } .ing .x { grid-row: 1; grid-column: 3; } }
    .x, .moves button { width: 36px; height: 36px; border-radius: 10px; border: 1px solid var(--line); background: var(--bg); color: var(--ink); cursor: pointer; }
    .moves button:disabled { opacity: .35; cursor: default; }
    .step { display: flex; flex-direction: column; gap: 8px; padding: 12px; border-radius: 14px; background: var(--bg); border: 1px solid var(--line); }
    .step-head { display: flex; justify-content: space-between; align-items: center; }
    .num { width: 30px; height: 30px; border-radius: 999px; background: var(--green); color: var(--on-accent); display: grid; place-items: center; font-weight: 700; }
    .moves { display: flex; gap: 6px; }
    details summary { cursor: pointer; font-size: 13px; color: var(--muted); padding: 4px 0; }
    details textarea { margin-top: 6px; }
    .step-photo { display: flex; align-items: center; gap: 10px; }
    .step-photo img { width: 72px; height: 54px; object-fit: cover; border-radius: 8px; }
    .step-photo label { cursor: pointer; }
    .sticky { position: sticky; top: 84px; }
    .check { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; font-size: 14px; }
    .check li { display: flex; gap: 8px; color: var(--muted); }
    .check li.ok { color: var(--green-ink); }
    .err { margin: 0; padding-left: 18px; color: var(--brick); font-size: 14px; }
    .danger { background: var(--brick); color: var(--on-accent); }
    .link-danger { border: 0; background: none; padding: 0; color: var(--brick); font: inherit; font-size: 14px; cursor: pointer; align-self: flex-start; }
  `]
})
export class MyRecipeEditPage implements OnInit {
  private api = inject(ApiService);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private seo = inject(SeoService);
  private toasts = inject(ToastService);
  readonly auth = inject(AuthService);
  readonly units = UNITS;

  readonly id = signal<number | null>(null);
  readonly row = signal<MyRecipeRow | null>(null);
  readonly categories = signal<Category[]>([]);
  readonly loading = signal(true);
  readonly busy = signal(false);
  readonly uploading = signal<string | null>(null);
  readonly errors = signal<string[]>([]);
  readonly confirmDelete = signal(false);
  readonly coverUrl = signal<string | null>(null);
  private coverId: number | null = null;
  /** Счётчик правок, чтобы чек-лист пересчитывался при наборе (поля формы — не сигналы). */
  private readonly tick = signal(0);

  title = '';
  lead = '';
  categoryId: number | null = null;
  time: number | null = null;
  servings = 4;
  difficulty = 'easy';
  vegetarian = false;
  glutenFree = false;
  ingredients: IngredientRow[] = [{ name: '', amount: '', unit: 'г' }, { name: '', amount: '', unit: 'г' }];
  steps: StepRow[] = [this.emptyStep(), this.emptyStep()];

  readonly checklist = computed(() => {
    this.tick();
    return [
      { label: 'Название', ok: this.title.trim().length >= 3 },
      { label: 'Описание от 20 символов', ok: this.lead.trim().length >= 20 },
      { label: 'Время приготовления', ok: !!this.time && this.time > 0 },
      { label: 'Фото готового блюда', ok: !!this.coverUrl() },
      { label: 'Минимум два ингредиента', ok: this.ingredients.filter((i) => i.name.trim()).length >= 2 },
      { label: 'Минимум два шага', ok: this.steps.filter((s) => s.body.trim()).length >= 2 },
    ];
  });

  /** Поля формы — обычные свойства; input/change всплывают до формы — по ним пересчитываем чек-лист. */
  touch(): void {
    this.tick.update((n) => n + 1);
  }

  emptyStep(): StepRow {
    return { body: '', hint: '', tip: '', substitution: '', media_id: null, media_url: null };
  }

  ngOnInit(): void {
    this.seo.apply({ title: 'Мой рецепт', path: '/account/recipes', noindex: true });
    this.api.categories().subscribe((res) => this.categories.set(res.data));

    const idParam = this.route.snapshot.paramMap.get('id');
    const start = () => {
      if (!idParam || idParam === 'new') {
        this.loading.set(false);
        return;
      }
      this.id.set(Number(idParam));
      this.api.myRecipe(Number(idParam)).subscribe({
        next: (res) => {
          const r = res.data;
          this.row.set(r);
          this.title = r.title;
          this.lead = r.lead ?? '';
          this.categoryId = r.category?.id ?? null;
          this.time = r.time_minutes;
          this.servings = r.servings;
          this.difficulty = r.difficulty;
          this.vegetarian = r.is_vegetarian;
          this.glutenFree = r.is_gluten_free;
          this.coverId = r.cover?.id ?? null;
          this.coverUrl.set(r.cover?.url ?? null);
          this.ingredients = (r.ingredients ?? []).map((i) => ({ name: i.name, amount: i.amount?.toString() ?? '', unit: i.unit ?? 'г' }));
          this.steps = (r.steps ?? []).map((s) => ({
            body: s.body, hint: s.hint ?? '', tip: s.tip ?? '', substitution: s.substitution ?? '',
            media_id: s.media?.id ?? null, media_url: s.media?.url ?? null,
          }));
          if (!this.ingredients.length) this.ingredients.push({ name: '', amount: '', unit: 'г' });
          if (!this.steps.length) this.steps.push(this.emptyStep());
          this.loading.set(false);
          this.touch();
        },
        error: () => {
          this.toasts.error('Рецепт не найден');
          this.router.navigate(['/account/recipes']);
        },
      });
    };
    if (this.auth.loaded()) start(); else this.auth.restore().subscribe(start);
  }

  move(n: number, dir: -1 | 1): void {
    const [s] = this.steps.splice(n, 1);
    this.steps.splice(n + dir, 0, s);
    this.touch();
  }

  upload(event: Event, target: string): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;
    if (file.size > 8 * 1024 * 1024) {
      this.toasts.error('Фото больше 8 МБ — уменьшите его.');
      return;
    }

    this.uploading.set(target);
    this.api.uploadRecipePhoto(file).subscribe({
      next: (res) => {
        this.uploading.set(null);
        if (target === 'cover') {
          this.coverId = res.data.id;
          this.coverUrl.set(res.data.url);
        } else {
          const step = this.steps[Number(target.slice(4))];
          if (step) { step.media_id = res.data.id; step.media_url = res.data.url; }
        }
        this.touch();
      },
      error: (err) => {
        this.uploading.set(null);
        this.toasts.error(errorText(err));
      },
    });
  }

  private payload(): Record<string, unknown> {
    const num = (v: string) => (v.trim() === '' ? null : Number(v.replace(',', '.')));
    return {
      title: this.title.trim(),
      lead: this.lead.trim() || null,
      category_id: this.categoryId,
      time_minutes: this.time || null,
      servings: this.servings || 4,
      difficulty: this.difficulty,
      is_vegetarian: this.vegetarian,
      is_gluten_free: this.glutenFree,
      cover_media_id: this.coverId,
      ingredients: this.ingredients.filter((i) => i.name.trim())
        .map((i) => ({ name: i.name.trim(), amount: num(i.amount), unit: i.unit || null })),
      steps: this.steps.filter((s) => s.body.trim()).map((s) => ({
        body: s.body.trim(), hint: s.hint.trim() || null, tip: s.tip.trim() || null,
        substitution: s.substitution.trim() || null, media_id: s.media_id,
      })),
    };
  }

  /** Сохранить; если submit — сразу отправить на модерацию. */
  save(submit: boolean): void {
    if (this.title.trim().length < 3) {
      this.errors.set(['Назовите рецепт — хотя бы три буквы.']);
      return;
    }
    if (submit && this.checklist().some((c) => !c.ok)) {
      this.errors.set(['Заполните пункты чек-листа — без них редакция не сможет проверить рецепт.']);
      return;
    }

    this.busy.set(true);
    this.errors.set([]);
    this.api.saveMyRecipe(this.payload(), this.id() ?? undefined).subscribe({
      next: (res) => {
        const id = res.data.id;
        if (!this.id()) {
          this.id.set(id);
          this.router.navigate(['/account/recipes', id], { replaceUrl: true });
        }
        this.row.set(res.data);
        if (!submit) {
          this.busy.set(false);
          this.toasts.show('Черновик сохранён');
          return;
        }
        this.api.submitMyRecipe(id).subscribe({
          next: (r) => {
            this.busy.set(false);
            this.row.set({ ...this.row()!, ...r.data });
            this.toasts.show('Рецепт отправлен на модерацию');
            this.router.navigate(['/account/recipes']);
          },
          error: (err) => this.fail(err),
        });
      },
      error: (err) => this.fail(err),
    });
  }

  withdraw(): void {
    const id = this.id();
    if (!id) return;
    this.busy.set(true);
    this.api.withdrawMyRecipe(id).subscribe({
      next: (r) => {
        this.busy.set(false);
        this.row.set({ ...this.row()!, ...r.data });
        this.toasts.show('Рецепт снова черновик — можно править');
      },
      error: (err) => this.fail(err),
    });
  }

  remove(): void {
    const id = this.id();
    if (!id) return;
    this.api.deleteMyRecipe(id).subscribe({
      next: () => {
        this.toasts.show('Черновик удалён');
        this.router.navigate(['/account/recipes']);
      },
      error: (err) => this.fail(err),
    });
  }

  private fail(err: unknown): void {
    this.busy.set(false);
    const fields = ((err as { error?: { errors?: Record<string, string[]> } })?.error?.errors ?? {});
    const list = Object.values(fields).flat();
    this.errors.set(list.length ? list : [errorText(err)]);
  }
}
