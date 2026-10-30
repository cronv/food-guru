import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, catchError, of, switchMap, tap, throwError } from 'rxjs';

import { API_BASE_URL } from '../tokens';
import {
  AcademyCard, AcademyRegistrationRow, AcademySession, AdminStats, ActivityRow,
  Category, CollectionCard, CollectionDetail, Contest, ContestCard, ContestEntry, CookbookNote,
  Envelope, HomeData, JournalIssue, JournalIssueCard, JournalPeriods, Leaderboard,
  Media, MyRating, MyRecipeRow, Paginated, Reaction, ReactionState, Recipe, RecipeCard, RecipeReportRow,
  ReportReason, ReportStatus, Review, User,
} from '../models/api.models';

export interface ContestFilters {
  q?: string;
  kind?: string[];
  /** active — идущие конкурсы, archive — с объявленными итогами. */
  scope?: 'active' | 'archive' | 'all';
  page?: number;
}

export interface JournalFilters {
  q?: string;
  year?: number;
  month?: number;
  period?: 'week' | 'month';
  page?: number;
}

export interface AcademyFilters {
  q?: string;
  format?: string[];
  level?: string[];
  scope?: 'upcoming' | 'archive' | 'all';
  page?: number;
}

export interface CatalogFilters {
  q?: string;
  category?: string;
  tags?: string[];
  max_time?: number;
  difficulty?: string[];
  diet?: string[];
  sort?: 'popular' | 'new' | 'fast';
  page?: number;
  per_page?: number;
}

@Injectable({ providedIn: 'root' })
export class ApiService {
  private http = inject(HttpClient);
  private base = inject(API_BASE_URL);

  /** Sanctum на cookie-сессии: без этого POST-запросы вернут 419. */
  private csrfReady = false;

  /* url, params и withCsrf открыты для соседних сервисов (CommerceService):
     токен CSRF должен запрашиваться в одном месте, а не в каждом сервисе заново. */
  url(path: string): string {
    return `${this.base}/api/v1/${path.replace(/^\//, '')}`;
  }

  params(filters: Record<string, unknown>): HttpParams {
    let params = new HttpParams();
    for (const [key, value] of Object.entries(filters)) {
      if (value === undefined || value === null || value === '') continue;
      if (Array.isArray(value)) {
        for (const item of value) params = params.append(`${key}[]`, String(item));
      } else {
        params = params.set(key, String(value));
      }
    }
    return params;
  }

  /**
   * Перед первым изменяющим запросом нужно получить XSRF-cookie.
   *
   * На 419 токен берём заново и повторяем запрос один раз. Так бывает:
   *  - при первом заходе в чистом браузере (ссылка из письма на телефоне):
   *    запрос «кто я» и запрос токена идут одновременно, и сервер заводит
   *    две сессии — cookie сессии и токен оказываются от разных;
   *  - когда сессия истекла, пока вкладка была открыта.
   * 419 означает, что до контроллера запрос не дошёл, поэтому повтор безопасен:
   * одноразовая ссылка входа не израсходована, деньги не списаны.
   */
  withCsrf<T>(request: () => Observable<T>): Observable<T> {
    const fetchToken = () => this.http
      .get(`${this.base}/sanctum/csrf-cookie`, { withCredentials: true, responseType: 'text' })
      .pipe(tap(() => (this.csrfReady = true)));

    const first = this.csrfReady ? request() : fetchToken().pipe(switchMap(() => request()));

    return first.pipe(
      catchError((err) => {
        if (err?.status !== 419) return throwError(() => err);
        this.csrfReady = false;
        return fetchToken().pipe(switchMap(() => request()));
      }),
    );
  }

  /* ---------- публичная часть ---------- */

  /** Лайк или дизлайк; null снимает оценку. */
  react(recipeId: number, reaction: Reaction | null): Observable<Envelope<ReactionState>> {
    const url = this.url(`recipes/${recipeId}/reaction`);
    return this.withCsrf(() => reaction
      ? this.http.put<Envelope<ReactionState>>(url, { reaction }, { withCredentials: true })
      : this.http.delete<Envelope<ReactionState>>(url, { withCredentials: true }));
  }

  /** Отметить просмотр. Только из браузера: сервер рендера не должен считаться зрителем. */
  recordView(slug: string): Observable<Envelope<{ views_count: number }>> {
    return this.withCsrf(() =>
      this.http.post<Envelope<{ views_count: number }>>(this.url(`recipes/${slug}/view`), {}, { withCredentials: true }),
    );
  }

  adminReports(status: ReportStatus | 'all', page = 1): Observable<Paginated<RecipeReportRow> & { meta: { open_total: number } }> {
    return this.http.get<Paginated<RecipeReportRow> & { meta: { open_total: number } }>(this.url('admin/reports'), {
      params: this.params({ status, page }),
      withCredentials: true,
    });
  }

  resolveReport(id: number, status: 'resolved' | 'rejected'): Observable<unknown> {
    return this.withCsrf(() =>
      this.http.post(this.url(`admin/reports/${id}/resolve`), { status }, { withCredentials: true }),
    );
  }

  reportRecipe(slug: string, payload: { reason: ReportReason; message: string }): Observable<unknown> {
    return this.withCsrf(() =>
      this.http.post(this.url(`recipes/${slug}/reports`), payload, { withCredentials: true }),
    );
  }

  reels(page = 1): Observable<Paginated<RecipeCard>> {
    return this.http.get<Paginated<RecipeCard>>(this.url('reels'), {
      params: this.params({ page }),
      withCredentials: true,
    });
  }

  uploadReel(recipeId: number, file: File): Observable<Envelope<{ reel_url: string | null }>> {
    const form = new FormData();
    form.append('file', file);

    return this.withCsrf(() =>
      this.http.post<Envelope<{ reel_url: string | null }>>(
        this.url(`admin/recipes/${recipeId}/reel`), form, { withCredentials: true }),
    );
  }

  deleteReel(recipeId: number): Observable<Envelope<{ reel_url: string | null }>> {
    return this.withCsrf(() =>
      this.http.delete<Envelope<{ reel_url: string | null }>>(
        this.url(`admin/recipes/${recipeId}/reel`), { withCredentials: true }),
    );
  }

  home(): Observable<Envelope<HomeData>> {
    return this.http.get<Envelope<HomeData>>(this.url('home'), { withCredentials: true });
  }

  recipes(filters: CatalogFilters = {}): Observable<Paginated<RecipeCard>> {
    return this.http.get<Paginated<RecipeCard>>(this.url('recipes'), {
      params: this.params(filters as Record<string, unknown>),
      withCredentials: true,
    });
  }

  recipe(slug: string): Observable<Envelope<Recipe>> {
    return this.http.get<Envelope<Recipe>>(this.url(`recipes/${slug}`), { withCredentials: true });
  }

  related(slug: string): Observable<Envelope<RecipeCard[]>> {
    return this.http.get<Envelope<RecipeCard[]>>(this.url(`recipes/${slug}/related`), {
      withCredentials: true,
    });
  }

  categories(): Observable<Envelope<Category[]>> {
    return this.http.get<Envelope<Category[]>>(this.url('categories'), { withCredentials: true });
  }

  category(slug: string): Observable<{ data: Category; recipes: Paginated<RecipeCard> }> {
    return this.http.get<{ data: Category; recipes: Paginated<RecipeCard> }>(
      this.url(`categories/${slug}`), { withCredentials: true });
  }

  collections(): Observable<Envelope<CollectionCard[]>> {
    return this.http.get<Envelope<CollectionCard[]>>(this.url('collections'), { withCredentials: true });
  }

  collection(slug: string): Observable<Envelope<CollectionDetail>> {
    return this.http.get<Envelope<CollectionDetail>>(this.url(`collections/${slug}`), {
      withCredentials: true,
    });
  }

  reviews(slug: string, page = 1): Observable<Paginated<Review>> {
    return this.http.get<Paginated<Review>>(this.url(`recipes/${slug}/reviews`), {
      params: this.params({ page }),
      withCredentials: true,
    });
  }

  addReview(slug: string, rating: number, body: string, photos: File[]): Observable<Envelope<Review>> {
    const form = new FormData();
    form.append('rating', String(rating));
    form.append('body', body);
    photos.forEach((file) => form.append('photos[]', file));

    return this.withCsrf(() =>
      this.http.post<Envelope<Review>>(this.url(`recipes/${slug}/reviews`), form, {
        withCredentials: true,
      }),
    );
  }

  /* ---------- аутентификация ---------- */

  register(name: string, email: string): Observable<Envelope<{ status: string; email: string }>> {
    return this.withCsrf(() =>
      this.http.post<Envelope<{ status: string; email: string }>>(
        this.url('auth/register'), { name, email }, { withCredentials: true }),
    );
  }

  requestLogin(email: string): Observable<Envelope<{ status: string }>> {
    return this.withCsrf(() =>
      this.http.post<Envelope<{ status: string }>>(
        this.url('auth/login'), { email }, { withCredentials: true }),
    );
  }

  loginWithPassword(email: string, password: string): Observable<Envelope<User>> {
    return this.withCsrf(() =>
      this.http.post<Envelope<User>>(
        this.url('auth/login/password'), { email, password }, { withCredentials: true }),
    );
  }

  /** Первый пароль текущего не требует, смена существующего — требует. */
  setPassword(payload: {
    password: string;
    password_confirmation: string;
    current_password?: string;
  }): Observable<Envelope<User>> {
    return this.withCsrf(() =>
      this.http.put<Envelope<User>>(this.url('me/password'), payload, { withCredentials: true }),
    );
  }

  verify(token: string): Observable<Envelope<User>> {
    return this.withCsrf(() =>
      this.http.post<Envelope<User>>(this.url('auth/verify'), { token }, { withCredentials: true }),
    );
  }

  confirmLogin(token: string): Observable<Envelope<User>> {
    return this.withCsrf(() =>
      this.http.post<Envelope<User>>(this.url('auth/login/confirm'), { token }, { withCredentials: true }),
    );
  }

  resend(email: string): Observable<Envelope<{ status: string }>> {
    return this.withCsrf(() =>
      this.http.post<Envelope<{ status: string }>>(
        this.url('auth/resend'), { email }, { withCredentials: true }),
    );
  }

  logout(): Observable<unknown> {
    return this.withCsrf(() =>
      this.http.post(this.url('auth/logout'), {}, { withCredentials: true }),
    );
  }

  me(): Observable<Envelope<User>> {
    return this.http.get<Envelope<User>>(this.url('me'), { withCredentials: true });
  }

  /** current_password нужен при смене почты, если у человека есть пароль. */
  updateMe(payload: { name?: string; email?: string; current_password?: string }): Observable<Envelope<User>> {
    return this.withCsrf(() =>
      this.http.patch<Envelope<User>>(this.url('me'), payload, { withCredentials: true }),
    );
  }

  /* ---------- рецепты читателя (через модерацию) ---------- */

  myRecipes(): Observable<{ data: MyRecipeRow[] }> {
    return this.http.get<{ data: MyRecipeRow[] }>(this.url('me/recipes'), { withCredentials: true });
  }

  myRecipe(id: number): Observable<Envelope<Recipe & MyRecipeRow>> {
    return this.http.get<Envelope<Recipe & MyRecipeRow>>(this.url(`me/recipes/${id}`), { withCredentials: true });
  }

  saveMyRecipe(data: Record<string, unknown>, id?: number): Observable<Envelope<Recipe & MyRecipeRow>> {
    return this.withCsrf(() => id
      ? this.http.patch<Envelope<Recipe & MyRecipeRow>>(this.url(`me/recipes/${id}`), data, { withCredentials: true })
      : this.http.post<Envelope<Recipe & MyRecipeRow>>(this.url('me/recipes'), data, { withCredentials: true }));
  }

  submitMyRecipe(id: number): Observable<Envelope<MyRecipeRow>> {
    return this.withCsrf(() => this.http.post<Envelope<MyRecipeRow>>(this.url(`me/recipes/${id}/submit`), {}, { withCredentials: true }));
  }

  withdrawMyRecipe(id: number): Observable<Envelope<MyRecipeRow>> {
    return this.withCsrf(() => this.http.post<Envelope<MyRecipeRow>>(this.url(`me/recipes/${id}/withdraw`), {}, { withCredentials: true }));
  }

  deleteMyRecipe(id: number): Observable<unknown> {
    return this.withCsrf(() => this.http.delete(this.url(`me/recipes/${id}`), { withCredentials: true }));
  }

  /** Фото для своего рецепта: только картинки до 8 МБ. */
  uploadRecipePhoto(file: File): Observable<Envelope<{ id: number; url: string }>> {
    const form = new FormData();
    form.append('file', file);
    return this.withCsrf(() =>
      this.http.post<Envelope<{ id: number; url: string }>>(this.url('me/recipes/media'), form, { withCredentials: true }));
  }

  /* ---------- рецептосохранялка ---------- */

  cookbookRecipes(page = 1): Observable<Paginated<RecipeCard>> {
    return this.http.get<Paginated<RecipeCard>>(this.url('me/cookbook/recipes'), {
      params: this.params({ page }),
      withCredentials: true,
    });
  }

  /** Добавить рецепт в рецептосохранялку или убрать его; в ответе — новый счётчик. */
  toggleCookbook(recipeId: number, save: boolean): Observable<Envelope<{ in_cookbook: boolean; saves_count: number }>> {
    const url = this.url(`recipes/${recipeId}/cookbook`);
    return this.withCsrf(() => save
      ? this.http.put<Envelope<{ in_cookbook: boolean; saves_count: number }>>(url, {}, { withCredentials: true })
      : this.http.delete<Envelope<{ in_cookbook: boolean; saves_count: number }>>(url, { withCredentials: true }));
  }

  cookbookNotes(page = 1): Observable<Paginated<CookbookNote>> {
    return this.http.get<Paginated<CookbookNote>>(this.url('me/cookbook/notes'), {
      params: this.params({ page }),
      withCredentials: true,
    });
  }

  saveCookbookNote(note: { title: string; url: string | null; body: string | null }, id?: number): Observable<Envelope<CookbookNote>> {
    return this.withCsrf(() => id
      ? this.http.patch<Envelope<CookbookNote>>(this.url(`me/cookbook/notes/${id}`), note, { withCredentials: true })
      : this.http.post<Envelope<CookbookNote>>(this.url('me/cookbook/notes'), note, { withCredentials: true }));
  }

  deleteCookbookNote(id: number): Observable<unknown> {
    return this.withCsrf(() =>
      this.http.delete(this.url(`me/cookbook/notes/${id}`), { withCredentials: true }),
    );
  }

  /* ---------- избранное ---------- */

  favorites(page = 1): Observable<Paginated<RecipeCard>> {
    return this.http.get<Paginated<RecipeCard>>(this.url('me/favorites'), {
      params: this.params({ page }),
      withCredentials: true,
    });
  }

  addFavorite(id: number): Observable<Envelope<{ favorite: boolean }>> {
    return this.withCsrf(() =>
      this.http.put<Envelope<{ favorite: boolean }>>(
        this.url(`recipes/${id}/favorite`), {}, { withCredentials: true }),
    );
  }

  removeFavorite(id: number): Observable<Envelope<{ favorite: boolean }>> {
    return this.withCsrf(() =>
      this.http.delete<Envelope<{ favorite: boolean }>>(
        this.url(`recipes/${id}/favorite`), { withCredentials: true }),
    );
  }

  /* ---------- админка ---------- */

  adminStats(): Observable<Envelope<AdminStats>> {
    return this.http.get<Envelope<AdminStats>>(this.url('admin/stats'), { withCredentials: true });
  }

  adminRecipes(filters: Record<string, unknown> = {}): Observable<Paginated<RecipeCard>> {
    return this.http.get<Paginated<RecipeCard>>(this.url('admin/recipes'), {
      params: this.params(filters),
      withCredentials: true,
    });
  }

  adminRecipe(id: number): Observable<Envelope<Recipe>> {
    return this.http.get<Envelope<Recipe>>(this.url(`admin/recipes/${id}`), { withCredentials: true });
  }

  createRecipe(payload: Record<string, unknown>): Observable<Envelope<Recipe>> {
    return this.withCsrf(() =>
      this.http.post<Envelope<Recipe>>(this.url('admin/recipes'), payload, { withCredentials: true }),
    );
  }

  updateRecipe(id: number, payload: Record<string, unknown>): Observable<Envelope<Recipe>> {
    return this.withCsrf(() =>
      this.http.patch<Envelope<Recipe>>(this.url(`admin/recipes/${id}`), payload, {
        withCredentials: true,
      }),
    );
  }

  moveRecipe(id: number, action: 'submit' | 'publish' | 'reject' | 'archive', note?: string)
    : Observable<Envelope<{ status: string; status_label: string }>> {
    return this.withCsrf(() =>
      this.http.post<Envelope<{ status: string; status_label: string }>>(
        this.url(`admin/recipes/${id}/${action}`),
        note ? { note } : {},
        { withCredentials: true },
      ),
    );
  }

  uploadMedia(file: File, alt?: string): Observable<Envelope<Media>> {
    const form = new FormData();
    form.append('file', file);
    if (alt) form.append('alt', alt);

    return this.withCsrf(() =>
      this.http.post<Envelope<Media>>(this.url('admin/media'), form, { withCredentials: true }),
    );
  }

  adminUsers(filters: Record<string, unknown> = {}): Observable<Paginated<User>> {
    return this.http.get<Paginated<User>>(this.url('admin/users'), {
      params: this.params(filters),
      withCredentials: true,
    });
  }

  updateUser(id: number, payload: { role?: string; is_active?: boolean }): Observable<Envelope<User>> {
    return this.withCsrf(() =>
      this.http.patch<Envelope<User>>(this.url(`admin/users/${id}`), payload, { withCredentials: true }),
    );
  }

  resendUserVerification(id: number): Observable<Envelope<{ status: string }>> {
    return this.withCsrf(() =>
      this.http.post<Envelope<{ status: string }>>(
        this.url(`admin/users/${id}/resend-verification`), {}, { withCredentials: true }),
    );
  }

  activity(page = 1): Observable<{ data: ActivityRow[]; meta: { current_page: number; last_page: number; total: number } }> {
    return this.http.get<{ data: ActivityRow[]; meta: { current_page: number; last_page: number; total: number } }>(
      this.url('admin/activity'), { params: this.params({ page }), withCredentials: true });
  }


  /* ---------- конкурсы ---------- */

  contests(filters: ContestFilters = {}): Observable<Paginated<ContestCard>> {
    return this.http.get<Paginated<ContestCard>>(this.url('contests'), {
      params: this.params(filters as Record<string, unknown>),
      withCredentials: true,
    });
  }

  contest(slug: string): Observable<Envelope<Contest>> {
    return this.http.get<Envelope<Contest>>(this.url(`contests/${slug}`), { withCredentials: true });
  }

  contestEntries(slug: string, sort: 'votes' | 'new' = 'votes', page = 1)
    : Observable<Paginated<ContestEntry>> {
    return this.http.get<Paginated<ContestEntry>>(this.url(`contests/${slug}/entries`), {
      params: this.params({ sort, page }),
      withCredentials: true,
    });
  }

  /** Отправить работу. Тип и предел размера файлов задаёт сам конкурс. */
  submitEntry(slug: string, files: File[], title?: string, body?: string)
    : Observable<Envelope<ContestEntry>> {
    const form = new FormData();
    files.forEach((file) => form.append('files[]', file));
    if (title) form.append('title', title);
    if (body) form.append('body', body);

    return this.withCsrf(() =>
      this.http.post<Envelope<ContestEntry>>(this.url(`contests/${slug}/entries`), form, {
        withCredentials: true,
      }),
    );
  }

  voteForEntry(slug: string, entryId: number)
    : Observable<Envelope<{ entry_id: number; votes_count: number }>> {
    return this.withCsrf(() =>
      this.http.post<Envelope<{ entry_id: number; votes_count: number }>>(
        this.url(`contests/${slug}/entries/${entryId}/vote`), {}, { withCredentials: true }),
    );
  }

  myContestEntries(page = 1): Observable<Paginated<ContestEntry>> {
    return this.http.get<Paginated<ContestEntry>>(this.url('me/contest-entries'), {
      params: this.params({ page }),
      withCredentials: true,
    });
  }

  /* ---------- кулинарная академия ---------- */

  academy(filters: AcademyFilters = {}): Observable<Paginated<AcademyCard>> {
    return this.http.get<Paginated<AcademyCard>>(this.url('academy'), {
      params: this.params(filters as Record<string, unknown>),
      withCredentials: true,
    });
  }

  academySession(slug: string): Observable<Envelope<AcademySession>> {
    return this.http.get<Envelope<AcademySession>>(this.url(`academy/${slug}`), {
      withCredentials: true,
    });
  }

  registerForSession(slug: string): Observable<Envelope<AcademySession>> {
    return this.withCsrf(() =>
      this.http.put<Envelope<AcademySession>>(
        this.url(`academy/${slug}/registration`), {}, { withCredentials: true }),
    );
  }

  unregisterFromSession(slug: string): Observable<Envelope<{ registered: boolean }>> {
    return this.withCsrf(() =>
      this.http.delete<Envelope<{ registered: boolean }>>(
        this.url(`academy/${slug}/registration`), { withCredentials: true }),
    );
  }

  myAcademy(page = 1): Observable<Paginated<AcademyCard>> {
    return this.http.get<Paginated<AcademyCard>>(this.url('me/academy'), {
      params: this.params({ page }),
      withCredentials: true,
    });
  }

  /* ---------- рейтинг ---------- */

  leaderboard(level?: string, page = 1): Observable<Leaderboard> {
    return this.http.get<Leaderboard>(this.url('rating'), {
      params: this.params({ level, page }),
      withCredentials: true,
    });
  }

  myRating(): Observable<Envelope<MyRating>> {
    return this.http.get<Envelope<MyRating>>(this.url('me/rating'), { withCredentials: true });
  }

  /* ---------- админка: конкурсы ---------- */

  adminContests(filters: Record<string, unknown> = {}): Observable<Paginated<ContestCard>> {
    return this.http.get<Paginated<ContestCard>>(this.url('admin/contests'), {
      params: this.params(filters),
      withCredentials: true,
    });
  }

  adminContest(id: number): Observable<Envelope<Contest>> {
    return this.http.get<Envelope<Contest>>(this.url(`admin/contests/${id}`), { withCredentials: true });
  }

  createContest(payload: Record<string, unknown>): Observable<Envelope<Contest>> {
    return this.withCsrf(() =>
      this.http.post<Envelope<Contest>>(this.url('admin/contests'), payload, { withCredentials: true }),
    );
  }

  updateContest(id: number, payload: Record<string, unknown>): Observable<Envelope<Contest>> {
    return this.withCsrf(() =>
      this.http.patch<Envelope<Contest>>(this.url(`admin/contests/${id}`), payload, {
        withCredentials: true,
      }),
    );
  }

  moveContest(id: number, action: 'publish' | 'archive' | 'draft' | 'moderation')
    : Observable<Envelope<{ status: string; status_label: string }>> {
    return this.withCsrf(() =>
      this.http.post<Envelope<{ status: string; status_label: string }>>(
        this.url(`admin/contests/${id}/status/${action}`), {}, { withCredentials: true }),
    );
  }

  adminContestEntries(id: number, status?: string, page = 1): Observable<Paginated<ContestEntry>> {
    return this.http.get<Paginated<ContestEntry>>(this.url(`admin/contests/${id}/entries`), {
      params: this.params({ status, page }),
      withCredentials: true,
    });
  }

  moderateEntry(contestId: number, entryId: number, status: 'approved' | 'rejected', reason?: string)
    : Observable<Envelope<ContestEntry>> {
    return this.withCsrf(() =>
      this.http.post<Envelope<ContestEntry>>(
        this.url(`admin/contests/${contestId}/entries/${entryId}/moderate`),
        { status, reason },
        { withCredentials: true },
      ),
    );
  }

  setContestWinners(id: number, places: Array<{ entry_id: number; place: number }>)
    : Observable<Envelope<ContestEntry[]>> {
    return this.withCsrf(() =>
      this.http.post<Envelope<ContestEntry[]>>(
        this.url(`admin/contests/${id}/winners`), { places }, { withCredentials: true }),
    );
  }

  /* ---------- админка: академия ---------- */

  adminAcademy(filters: Record<string, unknown> = {}): Observable<Paginated<AcademyCard>> {
    return this.http.get<Paginated<AcademyCard>>(this.url('admin/academy'), {
      params: this.params(filters),
      withCredentials: true,
    });
  }

  adminAcademySession(id: number): Observable<Envelope<AcademySession>> {
    return this.http.get<Envelope<AcademySession>>(this.url(`admin/academy/${id}`), {
      withCredentials: true,
    });
  }

  createAcademySession(payload: Record<string, unknown>): Observable<Envelope<AcademySession>> {
    return this.withCsrf(() =>
      this.http.post<Envelope<AcademySession>>(this.url('admin/academy'), payload, {
        withCredentials: true,
      }),
    );
  }

  updateAcademySession(id: number, payload: Record<string, unknown>): Observable<Envelope<AcademySession>> {
    return this.withCsrf(() =>
      this.http.patch<Envelope<AcademySession>>(this.url(`admin/academy/${id}`), payload, {
        withCredentials: true,
      }),
    );
  }

  moveAcademySession(id: number, action: 'publish' | 'archive' | 'draft' | 'moderation')
    : Observable<Envelope<{ status: string; status_label: string }>> {
    return this.withCsrf(() =>
      this.http.post<Envelope<{ status: string; status_label: string }>>(
        this.url(`admin/academy/${id}/status/${action}`), {}, { withCredentials: true }),
    );
  }

  academyRegistrations(id: number, page = 1)
    : Observable<{ data: AcademyRegistrationRow[]; meta: { current_page: number; last_page: number; total: number } }> {
    return this.http.get<{ data: AcademyRegistrationRow[]; meta: { current_page: number; last_page: number; total: number } }>(
      this.url(`admin/academy/${id}/registrations`),
      { params: this.params({ page }), withCredentials: true },
    );
  }


  /* ---------- журнал ---------- */

  journal(filters: JournalFilters = {}): Observable<Paginated<JournalIssueCard>> {
    return this.http.get<Paginated<JournalIssueCard>>(this.url('journal'), {
      params: this.params(filters as Record<string, unknown>),
      withCredentials: true,
    });
  }

  /** Годы и месяцы, в которых есть выпуски. Отдельным запросом: фильтр не должен
   *  пересобираться под уже выбранный фильтр. */
  journalPeriods(): Observable<JournalPeriods> {
    return this.http.get<JournalPeriods>(this.url('journal/periods'), { withCredentials: true });
  }

  journalIssue(slug: string): Observable<Envelope<JournalIssue>> {
    return this.http.get<Envelope<JournalIssue>>(this.url(`journal/${slug}`), {
      withCredentials: true,
    });
  }

  /** Адрес скачивания. Файл отдаёт сервер, счётчик считает он же. */
  journalDownloadUrl(slug: string): string {
    return this.url(`journal/${slug}/download`);
  }

  /* ---------- админка: журнал ---------- */

  adminJournal(filters: Record<string, unknown> = {}): Observable<Paginated<JournalIssueCard>> {
    return this.http.get<Paginated<JournalIssueCard>>(this.url('admin/journal'), {
      params: this.params(filters),
      withCredentials: true,
    });
  }

  adminJournalIssue(id: number): Observable<Envelope<JournalIssue>> {
    return this.http.get<Envelope<JournalIssue>>(this.url(`admin/journal/${id}`), {
      withCredentials: true,
    });
  }

  createJournalIssue(payload: Record<string, unknown>): Observable<Envelope<JournalIssue>> {
    return this.withCsrf(() =>
      this.http.post<Envelope<JournalIssue>>(this.url('admin/journal'), payload, {
        withCredentials: true,
      }),
    );
  }

  updateJournalIssue(id: number, payload: Record<string, unknown>): Observable<Envelope<JournalIssue>> {
    return this.withCsrf(() =>
      this.http.patch<Envelope<JournalIssue>>(this.url(`admin/journal/${id}`), payload, {
        withCredentials: true,
      }),
    );
  }

  uploadJournalFile(id: number, file: File): Observable<Envelope<JournalIssue>> {
    const form = new FormData();
    form.append('file', file);

    return this.withCsrf(() =>
      this.http.post<Envelope<JournalIssue>>(this.url(`admin/journal/${id}/file`), form, {
        withCredentials: true,
      }),
    );
  }

  deleteJournalFile(id: number): Observable<Envelope<JournalIssue>> {
    return this.withCsrf(() =>
      this.http.delete<Envelope<JournalIssue>>(this.url(`admin/journal/${id}/file`), {
        withCredentials: true,
      }),
    );
  }

  /** Перерисовать обложку из первой страницы PDF. */
  renderJournalCover(id: number): Observable<Envelope<JournalIssue>> {
    return this.withCsrf(() =>
      this.http.post<Envelope<JournalIssue>>(
        this.url(`admin/journal/${id}/cover`), {}, { withCredentials: true }),
    );
  }

  moveJournalIssue(id: number, action: 'publish' | 'archive' | 'draft' | 'moderation')
    : Observable<Envelope<{ status: string; status_label: string }>> {
    return this.withCsrf(() =>
      this.http.post<Envelope<{ status: string; status_label: string }>>(
        this.url(`admin/journal/${id}/status/${action}`), {}, { withCredentials: true }),
    );
  }

  /** Заглушка, чтобы цепочка csrf не падала при SSR. */
  noop(): Observable<null> {
    return of(null);
  }
}
