import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';

import { API_BASE_URL } from '@shared/tokens';
import { AuthService } from '@shared/services/auth.service';
import { BottomNavComponent } from './bottom-nav.component';

describe('BottomNavComponent', () => {
  let fixture: ComponentFixture<BottomNavComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [BottomNavComponent],
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: '' },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(BottomNavComponent);
    fixture.detectChanges();
  });

  it('показывает ровно пять ярлыков: больше не читается на телефоне', () => {
    const items = fixture.nativeElement.querySelectorAll('.bar a, .bar button');
    expect(items.length).toBe(5);
  });

  it('у каждой иконки есть подпись словом', () => {
    const labels = Array.from(
      fixture.nativeElement.querySelectorAll('.bar span') as NodeListOf<HTMLElement>,
    ).map((el) => el.textContent?.trim());

    expect(labels).toEqual(['Главная', 'Рецепты', 'Конкурсы', 'Академия', 'Ещё']);
  });

  it('иконки спрятаны от скринридера — смысл несёт подпись', () => {
    const icons = fixture.nativeElement.querySelectorAll('.bar svg');
    icons.forEach((icon: SVGElement) => {
      expect(icon.getAttribute('aria-hidden')).toBe('true');
    });
  });

  it('лист с остальными разделами закрыт, пока его не открыли', () => {
    expect(fixture.nativeElement.querySelector('#more-sheet')).toBeNull();

    const more = fixture.nativeElement.querySelector('.more') as HTMLButtonElement;
    expect(more.getAttribute('aria-expanded')).toBe('false');
  });

  it('кнопка «Ещё» открывает лист и сообщает об этом', () => {
    const more = fixture.nativeElement.querySelector('.more') as HTMLButtonElement;
    more.click();
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('#more-sheet')).not.toBeNull();
    expect(more.getAttribute('aria-expanded')).toBe('true');
  });

  it('гостю предлагается вход, а не личный кабинет', () => {
    (fixture.nativeElement.querySelector('.more') as HTMLButtonElement).click();
    fixture.detectChanges();

    const text = (fixture.nativeElement.querySelector('#more-sheet') as HTMLElement).textContent;
    expect(text).toContain('Войти');
    expect(text).not.toContain('Личный кабинет');
  });

  it('вошедшему показывается личный кабинет', () => {
    const auth = TestBed.inject(AuthService);
    auth.setUser({
      id: 1, name: 'Анна', email: 'a@food-guru.local', pending_email: null,
      role: 'user', role_label: 'Пользователь', email_verified: true, has_password: true,
      is_staff: false, is_active: true, created_at: null,
      rating: {
        points: 0, level: 'novice', level_label: 'Новичок', next_level: 'cook',
        next_level_label: 'Повар', points_to_next: 100, progress: 0,
        streak_days: 0, streak_best: 0, visits_total: 0,
      },
    });
    fixture.detectChanges();

    (fixture.nativeElement.querySelector('.more') as HTMLButtonElement).click();
    fixture.detectChanges();

    const text = (fixture.nativeElement.querySelector('#more-sheet') as HTMLElement).textContent;
    expect(text).toContain('Личный кабинет');
  });
});
