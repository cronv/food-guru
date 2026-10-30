import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { map } from 'rxjs';

import { AuthService } from '@shared/services/auth.service';
import { UserRole } from '@shared/models/api.models';

/**
 * Пускает в админку только сотрудников. Профиль подтягивается один раз,
 * дальше решение принимается по уже загруженному пользователю.
 */
export function staffGuard(minimum: UserRole = 'content_manager'): CanActivateFn {
  return () => {
    const auth = inject(AuthService);
    const router = inject(Router);

    const decide = (): boolean => {
      if (!auth.isAuthenticated()) {
        router.navigate(['/login']);
        return false;
      }
      if (!auth.atLeast(minimum)) {
        router.navigate(['/denied']);
        return false;
      }
      return true;
    };

    if (auth.loaded()) return decide();

    return auth.restore().pipe(map(() => decide()));
  };
}
