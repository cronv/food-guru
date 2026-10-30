import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';

import { Recipe, Step, User } from '@shared/models/api.models';
import { AuthService } from '@shared/services/auth.service';
import { API_BASE_URL } from '@shared/tokens';
import { MeasuresComponent } from './measures.component';
import { MediaActionsComponent } from './media-actions.component';
import { NutritionChartComponent, macroSlices } from './nutrition-chart.component';
import { ReportFormComponent } from './report-form.component';
import { StepTabsComponent } from './step-tabs.component';

const BASE = 'http://localhost:8000';
const providers = [
  provideRouter([]),
  provideHttpClient(),
  provideHttpClientTesting(),
  { provide: API_BASE_URL, useValue: BASE },
];

const text = (f: ComponentFixture<unknown>) => (f.nativeElement as HTMLElement).textContent?.replace(/\s+/g, ' ') ?? '';

describe('macroSlices', () => {
  it('считает энергию по 4/4/9 ккал и даёт ровно 100%', () => {
    const s = macroSlices(10, 20, 10);
    expect(s.map((x) => x.kcal)).toEqual([40, 80, 90]);
    expect(s.reduce((a, x) => a + x.share, 0)).toBe(100);
  });

  it('порядок фиксирован: белки, углеводы, жиры', () => {
    expect(macroSlices(1, 1, 1).map((s) => s.key)).toEqual(['protein', 'carbs', 'fat']);
  });

  it('округление не теряет процент на дробных долях', () => {
    expect(macroSlices(1, 1, 1).reduce((a, x) => a + x.share, 0)).toBe(100);
    expect(macroSlices(7, 13, 3).reduce((a, x) => a + x.share, 0)).toBe(100);
  });

  it('без БЖУ графика нет', () => {
    expect(macroSlices(0, 0, 0)).toEqual([]);
  });
});

describe('NutritionChartComponent', () => {
  it('пересчитывает калорийность на выбранное число порций', async () => {
    await TestBed.configureTestingModule({ imports: [NutritionChartComponent] }).compileComponents();
    const f = TestBed.createComponent(NutritionChartComponent);
    f.componentRef.setInput('protein', 9);
    f.componentRef.setInput('fat', 31);
    f.componentRef.setInput('carbs', 42);
    f.componentRef.setInput('calories', 480);
    f.componentRef.setInput('servings', 3);
    f.detectChanges();

    expect(text(f)).toContain('480 ккал');
    expect(text(f)).toContain('На 3 порции');
    expect(text(f)).toContain('1440 ккал');
    // Легенда-таблица дублирует цвет числами.
    expect(f.nativeElement.querySelectorAll('tbody tr').length).toBe(3);
  });
});

describe('MeasuresComponent', () => {
  it('таблица мер свёрнута и содержит ложки и стакан', async () => {
    await TestBed.configureTestingModule({ imports: [MeasuresComponent] }).compileComponents();
    const f = TestBed.createComponent(MeasuresComponent);
    f.detectChanges();

    const details = f.nativeElement.querySelector('details') as HTMLDetailsElement;
    expect(details.open).toBeFalse();
    for (const s of ['Чайная ложка', '5 мл', 'Десертная ложка', '10 мл', 'Столовая ложка', '15 мл', 'Стакан гранёный', '200 мл']) {
      expect(text(f)).withContext(s).toContain(s);
    }
  });
});

describe('StepTabsComponent', () => {
  const step: Step = { id: 7, position: 1, body: 'Шаг', media: null, tip: 'Не открывайте духовку', substitution: null, chef_secret: '' };

  it('вкладки свёрнуты, по нажатию открываются и закрываются', async () => {
    await TestBed.configureTestingModule({ imports: [StepTabsComponent] }).compileComponents();
    const f = TestBed.createComponent(StepTabsComponent);
    f.componentRef.setInput('step', step);
    f.detectChanges();

    const buttons = f.nativeElement.querySelectorAll('button') as NodeListOf<HTMLButtonElement>;
    expect(Array.from(buttons).map((b) => b.textContent?.trim())).toEqual(['Совет', 'Замена ингредиентов', 'Секреты шеф-повара']);
    expect(f.nativeElement.querySelector('.panel')).toBeNull();

    buttons[0].click();
    f.detectChanges();
    expect(text(f)).toContain('Не открывайте духовку');

    buttons[1].click();
    f.detectChanges();
    expect(text(f)).toContain('Замен для этого шага редакция пока не предлагает');

    buttons[1].click();
    f.detectChanges();
    expect(f.nativeElement.querySelector('.panel')).toBeNull();
  });
});

describe('ReportFormComponent', () => {
  let http: HttpTestingController;

  async function create(user: User | null) {
    await TestBed.configureTestingModule({ imports: [ReportFormComponent], providers }).compileComponents();
    TestBed.inject(AuthService).setUser(user);
    http = TestBed.inject(HttpTestingController);
    const f = TestBed.createComponent(ReportFormComponent);
    f.componentRef.setInput('slug', 'tiramisu');
    f.detectChanges();
    return f;
  }

  it('гостю предлагает войти, а не форму', async () => {
    const f = await create(null);
    expect(f.nativeElement.querySelector('form')).toBeNull();
    expect(text(f)).toContain('Войти');
  });

  it('без причины и с пустым «Другое» не отправляет', async () => {
    const f = await create({ id: 1 } as User);
    const cmp = f.componentInstance;

    cmp.submit(new Event('submit'));
    expect(cmp.error()).toBe('Выберите причину.');

    cmp.reason = 'other';
    cmp.message = 'плохо';
    cmp.submit(new Event('submit'));
    expect(cmp.error()).toContain('парой предложений');
    http.expectNone(() => true);
  });

  it('жалоба уходит на адрес рецепта', async () => {
    const f = await create({ id: 1 } as User);
    const cmp = f.componentInstance;
    cmp.reason = 'amounts';
    cmp.message = '  Маскарпоне 50 г на порцию — мало  ';
    cmp.submit(new Event('submit'));

    http.expectOne(`${BASE}/sanctum/csrf-cookie`).flush('');
    const req = http.expectOne(`${BASE}/api/v1/recipes/tiramisu/reports`);
    expect(req.request.body).toEqual({ reason: 'amounts', message: 'Маскарпоне 50 г на порцию — мало' });
    req.flush({});
    expect(cmp.sent()).toBeTrue();
  });
});

describe('MediaActionsComponent', () => {
  let http: HttpTestingController;

  const recipe = (extra: Partial<Recipe> = {}) => ({ id: 5, slug: 'r', title: 'R', ...extra }) as Recipe;

  async function create(r: Recipe, user: User | null = { id: 1 } as User) {
    await TestBed.configureTestingModule({ imports: [MediaActionsComponent], providers }).compileComponents();
    TestBed.inject(AuthService).setUser(user);
    http = TestBed.inject(HttpTestingController);
    const f = TestBed.createComponent(MediaActionsComponent);
    f.componentRef.setInput('recipe', r);
    f.detectChanges();
    return f;
  }

  it('без счётчиков с сервера оценка и просмотры не показываются; кнопки сохранения здесь нет', async () => {
    const f = await create(recipe());
    expect(f.nativeElement.querySelector('[aria-label^="Нравится"]')).toBeNull();
    expect(f.nativeElement.querySelector('.views')).toBeNull();
    // Сохранение — одно, в блоке справа на странице рецепта.
    expect(text(f)).not.toContain('избранн');
  });

  it('показывает просмотры и счётчики, когда сервер их отдаёт', async () => {
    const f = await create(recipe({ views_count: 1234, likes_count: 3, dislikes_count: 1, my_reaction: null }));
    expect(text(f)).toContain('1 234');
    expect(f.nativeElement.querySelector('[aria-label="Нравится, 3"]')).not.toBeNull();
  });

  it('лайк применяется сразу и откатывается при ошибке сервера', async () => {
    const f = await create(recipe({ likes_count: 3, dislikes_count: 1, my_reaction: null }));
    const cmp = f.componentInstance;

    cmp.react('like');
    expect(cmp.likes()).toBe(4);
    expect(cmp.mine()).toBe('like');

    http.expectOne(`${BASE}/sanctum/csrf-cookie`).flush('');
    http.expectOne(`${BASE}/api/v1/recipes/5/reaction`).flush({}, { status: 500, statusText: 'Error' });

    expect(cmp.likes()).toBe(3);
    expect(cmp.mine()).toBeNull();
  });

  it('смена лайка на дизлайк переносит голос', async () => {
    const f = await create(recipe({ likes_count: 3, dislikes_count: 1, my_reaction: 'like' }));
    const cmp = f.componentInstance;

    cmp.react('dislike');
    expect(cmp.likes()).toBe(2);
    expect(cmp.dislikes()).toBe(2);

    http.expectOne(`${BASE}/sanctum/csrf-cookie`).flush('');
    const req = http.expectOne(`${BASE}/api/v1/recipes/5/reaction`);
    expect(req.request.method).toBe('PUT');
    expect(req.request.body).toEqual({ reaction: 'dislike' });
    req.flush({ data: { likes_count: 2, dislikes_count: 2, my_reaction: 'dislike' } });
  });

  it('гость не оценивает: запросов нет', async () => {
    const f = await create(recipe({ likes_count: 0, dislikes_count: 0 }), null);
    f.componentInstance.react('like');
    expect(f.componentInstance.likes()).toBe(0);
    http.expectNone(() => true);
  });
});
