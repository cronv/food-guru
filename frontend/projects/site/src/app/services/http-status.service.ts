import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, InjectionToken, inject } from '@angular/core';
import { Meta } from '@angular/platform-browser';

/** Ответ express во время серверного рендера. В браузере и в ng serve его нет. */
export interface SsrResponse {
  status(code: number): unknown;
}

export const SSR_RESPONSE = new InjectionToken<SsrResponse>('SSR_RESPONSE');

/**
 * Код ответа для поисковиков. Без этого «Рецепт не найден» уходил с кодом 200 —
 * «мягкий 404»: робот индексирует пустую страницу и тратит на неё лимит обхода.
 */
@Injectable({ providedIn: 'root' })
export class HttpStatusService {
  private response = inject(SSR_RESPONSE, { optional: true });
  private meta = inject(Meta);

  notFound(): void {
    this.response?.status(404);
    // И запрет индексации — на случай, если код ответа где-то потеряется (прокси, кэш).
    this.meta.updateTag({ name: 'robots', content: 'noindex, nofollow' });
  }

  /**
   * Код по ошибке загрузки. 404 — только если API так и ответил. Упавший или
   * недоступный API — это 503: робот зайдёт позже, а не выкинет страницу из индекса
   * из-за пары минут простоя.
   */
  fromError(err: unknown): void {
    const status = err instanceof HttpErrorResponse ? err.status : 0;
    if (status === 404 || status === 403) {
      this.notFound();
      return;
    }
    this.response?.status(503);
  }
}
