import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { Observable, isObservable, of } from 'rxjs';

import { API_BASE_URL } from '@shared/tokens';
import { AuthService } from '@shared/services/auth.service';
import { User, UserRole } from '@shared/models/api.models';
import { staffGuard } from './guards';

describe('staffGuard', () => {
  let auth: AuthService;
  let router: Router;

  const person = (role: UserRole, isStaff: boolean): User => ({
    id: 1, name: 'Сотрудник', email: 's@food-guru.local', pending_email: null,
    role, role_label: role, email_verified: true, has_password: true, is_staff: isStaff,
    is_active: true, created_at: null,
    rating: {
      points: 0, level: 'novice', level_label: 'Новичок', next_level: 'cook',
      next_level_label: 'Повар', points_to_next: 100, progress: 0,
      streak_days: 0, streak_best: 0, visits_total: 0,
    },
  });

  /** Гард возвращает либо флаг, либо поток — приводим к одному виду. */
  const run = (minimum?: UserRole): Promise<boolean> => {
    const result = TestBed.runInInjectionContext(() =>
      (minimum ? staffGuard(minimum) : staffGuard())(null as never, null as never),
    );

    return isObservable(result)
      ? new Promise((resolve) => (result as Observable<boolean>).subscribe(resolve))
      : Promise.resolve(result as boolean);
  };

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: '' },
      ],
    });

    auth = TestBed.inject(AuthService);
    router = TestBed.inject(Router);
    spyOn(router, 'navigate').and.resolveTo(true);
  });

  it('гостя уводит на вход', async () => {
    auth.setUser(null);

    await expectAsync(run()).toBeResolvedTo(false);
    expect(router.navigate).toHaveBeenCalledWith(['/login']);
  });

  it('обычного пользователя уводит на отказ', async () => {
    auth.setUser(person('user', false));

    await expectAsync(run()).toBeResolvedTo(false);
    expect(router.navigate).toHaveBeenCalledWith(['/denied']);
  });

  it('контент-менеджера пускает', async () => {
    auth.setUser(person('content_manager', true));

    await expectAsync(run()).toBeResolvedTo(true);
    expect(router.navigate).not.toHaveBeenCalled();
  });

  it('контент-менеджера не пускает туда, где нужен руководитель', async () => {
    auth.setUser(person('content_manager', true));

    await expectAsync(run('editor_lead')).toBeResolvedTo(false);
    expect(router.navigate).toHaveBeenCalledWith(['/denied']);
  });

  it('администратора пускает везде: роли вложены', async () => {
    auth.setUser(person('admin', true));

    await expectAsync(run('editor_lead')).toBeResolvedTo(true);
    await expectAsync(run('admin')).toBeResolvedTo(true);
  });

  it('если профиль ещё не загружен, сначала подтягивает его', async () => {
    // loaded() ложно, пока никто не вызвал restore() или setUser().
    spyOn(auth, 'restore').and.callFake(() => {
      auth.setUser(person('editor_lead', true));
      return of(auth.user());
    });

    await expectAsync(run('editor_lead')).toBeResolvedTo(true);
    expect(auth.restore).toHaveBeenCalled();
  });
});
