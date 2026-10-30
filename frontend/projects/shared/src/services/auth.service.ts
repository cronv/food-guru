import { Injectable, inject, signal, computed } from '@angular/core';
import { Observable, catchError, map, of, tap } from 'rxjs';

import { ApiService } from './api.service';
import { User, UserRole } from '../models/api.models';

const ROLE_LEVEL: Record<UserRole, number> = {
  user: 0,
  content_manager: 1,
  editor_lead: 2,
  admin: 3,
};

@Injectable({ providedIn: 'root' })
export class AuthService {
  private api = inject(ApiService);

  readonly user = signal<User | null>(null);
  readonly loaded = signal(false);

  readonly isAuthenticated = computed(() => this.user() !== null);
  readonly isVerified = computed(() => this.user()?.email_verified === true);
  readonly isStaff = computed(() => this.user()?.is_staff === true);

  /** Роли вложены: проверяем минимальный уровень, а не точное совпадение. */
  atLeast(role: UserRole): boolean {
    const current = this.user()?.role;
    return current ? ROLE_LEVEL[current] >= ROLE_LEVEL[role] : false;
  }

  /** Тихо подтягивает профиль: 401 здесь — норма, значит гость. */
  restore(): Observable<User | null> {
    return this.api.me().pipe(
      map((res) => res.data),
      catchError(() => of(null)),
      tap((user) => {
        this.user.set(user);
        this.loaded.set(true);
      }),
    );
  }

  setUser(user: User | null): void {
    this.user.set(user);
    this.loaded.set(true);
  }

  logout(): Observable<unknown> {
    return this.api.logout().pipe(tap(() => this.user.set(null)));
  }
}
