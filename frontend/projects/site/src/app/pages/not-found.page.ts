import { Component, inject } from '@angular/core';
import { RouterLink } from '@angular/router';

import { HttpStatusService } from '../services/http-status.service';
import { SeoService } from '../services/seo.service';

@Component({
    selector: 'app-not-found',
    imports: [RouterLink],
    template: `
    <div class="wrap band empty">
      <h1>Страница не найдена</h1>
      <p class="muted">Возможно, рецепт сняли с публикации или в адресе опечатка.</p>
      <a class="btn btn-green" routerLink="/">На главную</a>
    </div>
  `,
    styles: [`h1 { font-size: 32px; margin-bottom: 10px; } p { margin: 0 0 18px; }`]
})
export class NotFoundPage {
  constructor() {
    // Код 404 для робота: иначе несуществующий адрес попал бы в индекс.
    inject(HttpStatusService).notFound();
    inject(SeoService).apply({ title: 'Страница не найдена', noindex: true });
  }
}
