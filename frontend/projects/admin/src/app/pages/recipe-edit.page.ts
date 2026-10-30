import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';

import { ApiService } from '@shared/services/api.service';
import { AuthService } from '@shared/services/auth.service';
import { SITE_URL } from '@shared/tokens';
import { Category, Media, Recipe, RecipeStatus, STATUS_LABELS } from '@shared/models/api.models';
import { errorText } from '@shared/utils';
import { ToastService } from '../services/toast.service';

interface IngredientRow { name: string; amount: number | null; unit: string }
interface StepRow {
  body: string;
  hint: string;
  tip: string;
  substitution: string;
  chef_secret: string;
  media_id: number | null;
  media_url?: string | null;
}

const emptyStep = (): StepRow => ({ body: '', hint: '', tip: '', substitution: '', chef_secret: '', media_id: null });

@Component({
    selector: 'adm-recipe-edit',
    imports: [RouterLink, FormsModule],
    template: `
    <nav class="crumbs muted">
      <a routerLink="/recipes">Рецепты</a> / <span>{{ title || 'Новый рецепт' }}</span>
    </nav>

    <header class="head">
      <div class="row ident">
        <h1>{{ title || 'Новый рецепт' }}</h1>
        @if (status()) {
          <span class="chip" [class]="chipClass()">{{ statusLabel() }}</span>
        }
      </div>

      <div class="row">
        @if (id() && status() === 'published') {
          <a class="btn btn-ghost" [href]="siteUrl + '/recipes/' + slug" target="_blank" rel="noopener">
            Посмотреть на сайте ↗
          </a>
        }
        <button class="btn btn-ghost" type="button" [disabled]="saving()" (click)="save()">
          {{ saving() ? 'Сохраняем…' : 'Сохранить' }}
        </button>
        @if (id() && (status() === 'draft' || status() === 'rejected')) {
          <button class="btn btn-green" type="button" (click)="move('submit')">Отправить на модерацию</button>
        }
        @if (id() && status() === 'moderation' && canPublish()) {
          <button class="btn btn-green" type="button" (click)="move('publish')">Опубликовать</button>
        }
      </div>
    </header>

    @if (note()) {
      <div class="note note-warn">
        <strong>Комментарий модератора:</strong> {{ note() }}
      </div>
    }

    <div class="layout">
      <div class="main">
        <section class="panel">
          <h2>Основное</h2>

          <label class="fl" for="title">Название
            <input id="title" type="text" name="title" [(ngModel)]="title" placeholder="Например, тыквенный крем-суп">
          </label>

          <label class="fl" for="slug">Адрес
            <input id="slug" type="text" name="slug" [(ngModel)]="slug" placeholder="tykvennyy-krem-sup">
            <span class="muted weak">{{ siteUrl }}/recipes/{{ slug || '…' }}</span>
          </label>

          <label class="fl" for="lead">Краткое описание
            <textarea id="lead" name="lead" rows="3" [(ngModel)]="lead"
                      placeholder="Пара предложений о том, чем рецепт хорош"></textarea>
            <span class="muted weak">{{ lead.length }} из 600 символов</span>
          </label>

          <div class="three">
            <label class="fl" for="time">Время, мин
              <input id="time" type="number" name="time" [(ngModel)]="timeMinutes" min="1">
            </label>
            <label class="fl" for="serv">Порций
              <input id="serv" type="number" name="serv" [(ngModel)]="servings" min="1">
            </label>
            <label class="fl" for="diff">Сложность
              <select id="diff" name="diff" [(ngModel)]="difficulty">
                <option value="easy">Просто</option>
                <option value="medium">Средне</option>
                <option value="hard">Сложно</option>
              </select>
            </label>
          </div>
        </section>

        <section class="panel">
          <h2>Обложка</h2>
          @if (cover(); as c) {
            <div class="cover">
              <img [src]="c.url" alt="Обложка рецепта">
              <button class="btn btn-ghost btn-sm" type="button" (click)="cover.set(null)">Убрать</button>
            </div>
          } @else {
            <label class="upload">
              {{ uploading() ? 'Загружаем…' : '+ Загрузить обложку' }}
              <input type="file" accept="image/*" hidden (change)="uploadCover($event)">
            </label>
          }
          <p class="muted weak">JPEG, PNG или WebP до 8 МБ. Обложка идёт и в превью для Telegram.</p>
        </section>

        <section class="panel">
          <h2>Видеорецепт</h2>
          @if (id() === null) {
            <p class="muted weak">Сохраните рецепт — тогда можно будет загрузить ролик.</p>
          } @else {
            @if (reelUrl(); as url) {
              <video class="reel-preview" [src]="url" controls playsinline preload="metadata"></video>
              <button class="btn btn-ghost btn-sm" type="button" [disabled]="reelBusy()"
                      (click)="removeReel()">Удалить ролик</button>
            }
            <label class="upload">
              {{ reelBusy() ? 'Загружаем…' : (reelUrl() ? 'Заменить ролик' : '+ Загрузить ролик') }}
              <input type="file" accept="video/mp4,video/webm" hidden (change)="uploadReel($event)">
            </label>
            <p class="muted weak">
              MP4 или WebM до 80 МБ. Лучше вертикальное 9:16 до 30 секунд — так он ляжет в ленту рилсов.
            </p>
          }
        </section>

        <section class="panel">
          <div class="sec-row">
            <h2>Ингредиенты</h2>
            <button class="btn btn-ghost btn-sm" type="button" (click)="addIngredient()">+ Строка</button>
          </div>

          @for (ing of ingredients(); track $index; let i = $index) {
            <div class="ing">
              <label class="fl grow">Продукт
                <input type="text" [(ngModel)]="ing.name" [name]="'ing-name-' + i" placeholder="Мука пшеничная">
              </label>
              <label class="fl narrow">На порцию
                <input type="number" step="0.01" [(ngModel)]="ing.amount" [name]="'ing-amt-' + i">
              </label>
              <label class="fl narrow">Ед.
                <input type="text" [(ngModel)]="ing.unit" [name]="'ing-unit-' + i" placeholder="г">
              </label>
              <button class="del" type="button" (click)="removeIngredient(i)"
                      [attr.aria-label]="'Удалить строку ' + (i + 1)">✕</button>
            </div>
          }

          <p class="muted weak">Количество указывается на одну порцию — сайт умножает его на выбранное число.</p>
        </section>

        <section class="panel">
          <div class="sec-row">
            <h2>Шаги приготовления</h2>
            <button class="btn btn-ghost btn-sm" type="button" (click)="addStep()">+ Шаг</button>
          </div>

          @for (step of steps(); track $index; let i = $index) {
            <div class="step">
              <span class="num">{{ i + 1 }}</span>
              <div class="grow">
                <textarea rows="2" [(ngModel)]="step.body" [name]="'step-' + i"
                          placeholder="Что делать на этом шаге"></textarea>
                <input class="hint" type="text" maxlength="160" [(ngModel)]="step.hint"
                       [name]="'step-hint-' + i" placeholder="Подсказка: 200 °C · 9 минут"
                       [attr.aria-label]="'Подсказка к шагу ' + (i + 1)">
                <details class="extra" [open]="!!(step.tip || step.substitution || step.chef_secret)">
                  <summary>Вкладки шага: совет, замена, секрет шефа</summary>
                  <label class="fl">Совет
                    <textarea rows="2" maxlength="1000" [(ngModel)]="step.tip" [name]="'step-tip-' + i"
                              placeholder="Что поможет не ошибиться на этом шаге"></textarea>
                  </label>
                  <label class="fl">Замена ингредиентов
                    <textarea rows="2" maxlength="1000" [(ngModel)]="step.substitution" [name]="'step-sub-' + i"
                              placeholder="Чем заменить, если чего-то нет"></textarea>
                  </label>
                  <label class="fl">Секреты шеф-повара
                    <textarea rows="2" maxlength="1000" [(ngModel)]="step.chef_secret" [name]="'step-chef-' + i"
                              placeholder="Приём, который отличает хорошее блюдо от отличного"></textarea>
                  </label>
                </details>
                @if (step.media_url) {
                  <img class="step-shot" [src]="step.media_url" [alt]="'Фото шага ' + (i + 1)">
                }
              </div>
              <button class="del" type="button" (click)="removeStep(i)"
                      [attr.aria-label]="'Удалить шаг ' + (i + 1)">✕</button>
            </div>
          }
        </section>
      </div>

      <aside class="side">
        <section class="panel">
          <h2>Публикация</h2>
          <p class="muted weak">
            Статус меняется кнопками сверху. Публиковать может руководитель редакции.
          </p>
          <label class="fl" for="cat">Категория
            <select id="cat" name="cat" [(ngModel)]="categoryId">
              <option [ngValue]="null">Без категории</option>
              @for (c of categories(); track c.slug) {
                <option [ngValue]="c.id">{{ c.title }}</option>
              }
            </select>
          </label>
          <label class="fl" for="tags">Теги через запятую
            <input id="tags" type="text" name="tags" [(ngModel)]="tagsText" placeholder="сыр, на ужин">
          </label>
        </section>

        <section class="panel">
          <h2>Превью для соцсетей</h2>
          <label class="fl" for="og">Описание для ссылки
            <textarea id="og" name="og" rows="2" [(ngModel)]="ogDescription"
                      placeholder="Короткая строка, которую увидят в Telegram"></textarea>
          </label>
          <div class="preview">
            @if (cover(); as c) { <img [src]="c.url" alt=""> } @else { <div class="ph"></div> }
            <div class="pv-text">
              <span class="muted weak">{{ siteUrl }}</span>
              <strong>{{ title || 'Название рецепта' }}</strong>
              <span class="muted weak">{{ ogDescription || lead || 'Описание появится здесь' }}</span>
            </div>
          </div>
        </section>

        <section class="panel">
          <h2>КБЖУ на порцию</h2>
          <div class="two">
            <label class="fl">Ккал<input type="number" [(ngModel)]="calories" name="cal" min="0"></label>
            <label class="fl">Белки<input type="number" [(ngModel)]="protein" name="prot" min="0"></label>
            <label class="fl">Жиры<input type="number" [(ngModel)]="fat" name="fat" min="0"></label>
            <label class="fl">Углеводы<input type="number" [(ngModel)]="carbs" name="carb" min="0"></label>
          </div>
          <label class="check"><input type="checkbox" [(ngModel)]="isVegetarian" name="veg"> Вегетарианское</label>
          <label class="check"><input type="checkbox" [(ngModel)]="isGlutenFree" name="gf"> Без глютена</label>
        </section>
      </aside>
    </div>
  `,
    styles: [`
    .crumbs { font-size: 13px; margin-bottom: 12px; }
    .head { display: flex; flex-wrap: wrap; gap: 16px; align-items: center; justify-content: space-between; margin-bottom: 20px; }
    .ident { align-items: center; gap: 14px; }
    h1 { font-size: 28px; }
    .layout { display: flex; flex-wrap: wrap; gap: 22px; align-items: flex-start; }
    .main { flex: 999 1 520px; min-width: 0; display: flex; flex-direction: column; gap: 20px; }
    .side { flex: 1 1 300px; min-width: 0; display: flex; flex-direction: column; gap: 20px; }
    .panel {
      background: var(--surface); border: 1px solid var(--line); border-radius: 16px;
      padding: 22px; display: flex; flex-direction: column; gap: 14px;
    }
    .panel h2 { font-family: var(--body); font-size: 18px; font-weight: 700; }
    .sec-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
    .weak { font-weight: 400; font-size: 13px; }
    .three { display: flex; flex-wrap: wrap; gap: 14px; }
    .three label { flex: 1 1 140px; min-width: 0; }
    .two { display: flex; flex-wrap: wrap; gap: 12px; }
    .two label { flex: 1 1 100px; min-width: 0; }
    .ing { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
    .grow { flex: 999 1 200px; min-width: 0; }
    .narrow { flex: 1 1 100px; min-width: 0; }
    .del {
      flex: 0 0 auto; width: 46px; height: 46px; border: 1px solid var(--line);
      border-radius: 12px; background: var(--surface); color: var(--brick-ink);
    }
    .step { display: flex; gap: 14px; align-items: flex-start; }
    .num {
      flex: 0 0 auto; width: 34px; height: 34px; margin-top: 6px; border-radius: 999px;
      background: var(--green); color: var(--on-accent); display: grid; place-items: center; font-weight: 700;
    }
    .step-shot { margin-top: 8px; border-radius: 10px; max-height: 150px; }
    .hint { margin-top: 8px; width: 100%; }
    .extra { margin-top: 8px; border: 1px dashed var(--line); border-radius: 10px; padding: 0 10px; }
    .extra summary { cursor: pointer; min-height: 36px; display: flex; align-items: center; font-size: 13px; color: var(--muted); }
    .extra[open] { padding-bottom: 10px; }
    .extra .fl { margin-top: 8px; }
    .reel-preview { width: 100%; max-width: 240px; aspect-ratio: 9 / 16; border-radius: 12px; background: #17120e; }
    .cover { display: flex; flex-direction: column; gap: 10px; align-items: flex-start; }
    .cover img { border-radius: 12px; max-height: 220px; }
    .upload {
      border: 1.5px dashed var(--line); border-radius: 12px; padding: 24px; text-align: center;
      color: var(--muted); font-weight: 600; cursor: pointer;
    }
    .preview { border: 1px solid var(--line); border-radius: 12px; overflow: hidden; }
    .preview img, .ph { width: 100%; height: 110px; object-fit: cover; display: block; background: var(--sunken); }
    .pv-text { padding: 12px 14px; display: flex; flex-direction: column; gap: 2px; font-size: 13px; }
    .check { display: flex; align-items: center; gap: 10px; font-size: 15px; }
  `]
})
export class RecipeEditPage implements OnInit {
  private api = inject(ApiService);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private toasts = inject(ToastService);
  private auth = inject(AuthService);
  siteUrl = inject(SITE_URL);

  readonly id = signal<number | null>(null);
  readonly status = signal<RecipeStatus | null>(null);
  readonly note = signal<string | null>(null);
  readonly saving = signal(false);
  readonly uploading = signal(false);
  readonly cover = signal<Media | null>(null);
  readonly categories = signal<Category[]>([]);
  readonly ingredients = signal<IngredientRow[]>([{ name: '', amount: null, unit: '' }]);
  readonly steps = signal<StepRow[]>([emptyStep()]);
  readonly reelUrl = signal<string | null>(null);
  readonly reelBusy = signal(false);

  title = '';
  slug = '';
  lead = '';
  ogDescription = '';
  timeMinutes: number | null = null;
  servings = 4;
  difficulty: 'easy' | 'medium' | 'hard' = 'easy';
  categoryId: number | null = null;
  tagsText = '';
  calories: number | null = null;
  protein: number | null = null;
  fat: number | null = null;
  carbs: number | null = null;
  isVegetarian = false;
  isGlutenFree = false;

  ngOnInit(): void {
    this.api.categories().subscribe({
      next: (res) => this.categories.set(res.data),
      error: () => this.categories.set([]),
    });

    const param = this.route.snapshot.paramMap.get('id');
    if (param && param !== 'new') {
      this.id.set(Number(param));
      this.load(Number(param));
    }
  }

  private load(id: number): void {
    this.api.adminRecipe(id).subscribe({
      next: (res) => this.fill(res.data),
      error: (err) => this.toasts.error(errorText(err, 'Рецепт не найден или нет доступа.')),
    });
  }

  private fill(r: Recipe): void {
    this.title = r.title;
    this.slug = r.slug;
    this.lead = r.lead ?? '';
    this.ogDescription = r.og_description ?? '';
    this.timeMinutes = r.time_minutes;
    this.servings = r.servings;
    this.difficulty = r.difficulty;
    this.categoryId = r.category?.id ?? null;
    this.tagsText = (r.tags || []).map((t) => t.title).join(', ');
    this.calories = r.nutrition.calories;
    this.protein = r.nutrition.protein;
    this.fat = r.nutrition.fat;
    this.carbs = r.nutrition.carbs;
    this.isVegetarian = r.is_vegetarian;
    this.isGlutenFree = r.is_gluten_free;

    this.status.set(r.status);
    this.note.set(r.moderation_note ?? null);
    this.cover.set(r.cover ?? null);
    this.reelUrl.set(r.reel_url ?? null);

    this.ingredients.set(
      (r.ingredients || []).map((i) => ({ name: i.name, amount: i.amount, unit: i.unit ?? '' })),
    );
    if (!this.ingredients().length) this.addIngredient();

    this.steps.set(
      (r.steps || []).map((s) => ({
        body: s.body, hint: s.hint ?? '',
        tip: s.tip ?? '', substitution: s.substitution ?? '', chef_secret: s.chef_secret ?? '',
        media_id: s.media?.id ?? null, media_url: s.media?.url ?? null,
      })),
    );
    if (!this.steps().length) this.addStep();
  }

  canPublish(): boolean {
    return this.auth.atLeast('editor_lead');
  }

  statusLabel(): string {
    const s = this.status();
    return s ? STATUS_LABELS[s] : '';
  }

  chipClass(): string {
    const s = this.status();
    if (s === 'published') return 'chip-g';
    if (s === 'moderation') return 'chip-b';
    return '';
  }

  addIngredient(): void {
    this.ingredients.update((list) => [...list, { name: '', amount: null, unit: '' }]);
  }

  removeIngredient(i: number): void {
    this.ingredients.update((list) => list.filter((_, k) => k !== i));
  }

  addStep(): void {
    this.steps.update((list) => [...list, emptyStep()]);
  }

  removeStep(i: number): void {
    this.steps.update((list) => list.filter((_, k) => k !== i));
  }

  uploadCover(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;

    this.uploading.set(true);
    this.api.uploadMedia(file, this.title).subscribe({
      next: (res) => {
        this.cover.set(res.data);
        this.uploading.set(false);
      },
      error: (err) => {
        this.uploading.set(false);
        this.toasts.error(errorText(err));
      },
    });
  }

  uploadReel(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    const id = this.id();
    input.value = '';
    if (!file || id === null) return;

    this.reelBusy.set(true);
    this.api.uploadReel(id, file).subscribe({
      next: (res) => {
        this.reelUrl.set(res.data.reel_url);
        this.reelBusy.set(false);
        this.toasts.show('Ролик загружен');
      },
      error: (err) => {
        this.reelBusy.set(false);
        this.toasts.error(errorText(err));
      },
    });
  }

  removeReel(): void {
    const id = this.id();
    if (id === null) return;

    this.reelBusy.set(true);
    this.api.deleteReel(id).subscribe({
      next: () => {
        this.reelUrl.set(null);
        this.reelBusy.set(false);
      },
      error: (err) => {
        this.reelBusy.set(false);
        this.toasts.error(errorText(err));
      },
    });
  }

  private payload(): Record<string, unknown> {
    return {
      title: this.title.trim(),
      slug: this.slug.trim() || undefined,
      lead: this.lead.trim() || null,
      og_description: this.ogDescription.trim() || null,
      category_id: this.categoryId,
      cover_media_id: this.cover()?.id ?? null,
      time_minutes: this.timeMinutes,
      servings: this.servings,
      difficulty: this.difficulty,
      calories: this.calories,
      protein: this.protein,
      fat: this.fat,
      carbs: this.carbs,
      is_vegetarian: this.isVegetarian,
      is_gluten_free: this.isGlutenFree,
      tags: this.tagsText.split(',').map((t) => t.trim()).filter(Boolean),
      ingredients: this.ingredients()
        .filter((i) => i.name.trim())
        .map((i) => ({ name: i.name.trim(), amount: i.amount, unit: i.unit.trim() || null })),
      steps: this.steps()
        .filter((s) => s.body.trim())
        .map((s) => ({
          body: s.body.trim(),
          hint: s.hint.trim() || null,
          // Шаги переписываются целиком: не отправить поле — значит стереть его.
          tip: s.tip.trim() || null,
          substitution: s.substitution.trim() || null,
          chef_secret: s.chef_secret.trim() || null,
          media_id: s.media_id,
        })),
    };
  }

  save(): void {
    if (!this.title.trim()) {
      this.toasts.error('Укажите название');
      return;
    }

    this.saving.set(true);
    const id = this.id();
    const request = id
      ? this.api.updateRecipe(id, this.payload())
      : this.api.createRecipe(this.payload());

    request.subscribe({
      next: (res) => {
        this.saving.set(false);
        this.toasts.show('Сохранено');

        if (!id) {
          this.id.set(res.data.id);
          this.fill(res.data);
          this.router.navigate(['/recipes', res.data.id], { replaceUrl: true });
        } else {
          this.status.set(res.data.status);
        }
      },
      error: (err) => {
        this.saving.set(false);
        this.toasts.error(errorText(err));
      },
    });
  }

  move(action: 'submit' | 'publish'): void {
    const id = this.id();
    if (!id) return;

    this.api.moveRecipe(id, action).subscribe({
      next: (res) => {
        this.status.set(res.data.status as RecipeStatus);
        this.note.set(null);
        this.toasts.show(`Статус: ${res.data.status_label}`);
      },
      error: (err) => this.toasts.error(errorText(err)),
    });
  }
}
