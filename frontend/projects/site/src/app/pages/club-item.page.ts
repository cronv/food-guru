import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';

import { ClubDetail } from '@shared/models/commerce.models';
import { CommerceService } from '@shared/services/commerce.service';
import { HttpStatusService } from '../services/http-status.service';
import { SeoService } from '../services/seo.service';

@Component({
    selector: 'app-club-item',
    imports: [RouterLink],
    template: `
    <div class="wrap band narrow">
      @if (item(); as i) {
        <nav class="crumbs muted"><a routerLink="/club">Закрытая академия</a> / <span>{{ i.kind_label }}</span></nav>
        <h1>{{ i.title }}</h1>
        <p class="muted meta">
          @if (i.chef_name) { {{ i.chef_name }} }
          @if (i.duration_minutes) { · {{ i.duration_minutes }} мин }
        </p>

        @if (i.unlocked) {
          @if (i.video_url) {
            <!-- Ссылка временная и подписанная; скачивание из меню плеера скрыто. -->
            <video class="video" [src]="i.video_url" [poster]="i.cover?.url ?? ''" controls preload="metadata"
                   playsinline controlsList="nodownload" disablepictureinpicture></video>
          } @else if (i.cover) {
            <img class="cover" [src]="i.cover.url" [alt]="i.cover.alt || i.title">
          }

          @if (i.lead) { <p class="lead">{{ i.lead }}</p> }
          @for (p of paragraphs(); track $index) { <p class="body">{{ p }}</p> }

          @if (i.recipe; as r) {
            <div class="recipe">
              @if (r.ingredients?.length) {
                <section>
                  <h2>Ингредиенты</h2>
                  <ul>@for (x of r.ingredients; track $index) { <li>{{ x }}</li> }</ul>
                </section>
              }
              @if (r.steps?.length) {
                <section>
                  <h2>Приготовление</h2>
                  <ol>@for (s of r.steps; track $index) { <li>{{ s }}</li> }</ol>
                </section>
              }
            </div>
          }
        } @else {
          <div class="teaser">
            @if (i.cover) { <img [src]="i.cover.url" alt=""> }
            <div class="veil">
              <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
              <p>{{ i.lead }}</p>
              <p class="small">Материал доступен по подписке на закрытую академию.</p>
              <a class="btn btn-green" routerLink="/club">Оформить подписку</a>
            </div>
          </div>
        }
      } @else if (error()) {
        <h1>Материал не найден</h1>
        <a class="btn btn-ghost" routerLink="/club">В закрытую академию</a>
      } @else {
        <div class="spinner"></div>
      }
    </div>
  `,
    styles: [`
    .narrow { max-width: 860px; }
    .crumbs { font-size: 13px; margin-bottom: 14px; }
    h1 { font-size: clamp(26px, 4vw, 40px); line-height: 1.12; }
    .meta { margin: 8px 0 18px; }
    .video, .cover { width: 100%; border-radius: 18px; background: #000; max-height: 70vh; }
    .cover { object-fit: cover; background: var(--sunken); }
    .lead { font-size: 18px; margin: 18px 0 8px; }
    .body { line-height: 1.7; }
    .recipe { display: flex; flex-wrap: wrap; gap: 28px; margin-top: 20px; }
    .recipe section { flex: 1 1 300px; }
    .recipe h2 { font-size: 22px; margin-bottom: 10px; }
    .recipe li { padding: 6px 0; line-height: 1.55; }
    .teaser { position: relative; border-radius: 20px; overflow: hidden; min-height: 360px; background: var(--panel); }
    .teaser img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; filter: blur(6px) brightness(.55); transform: scale(1.05); }
    .veil {
      position: relative; min-height: 360px; padding: 28px; color: #FFFBF4;
      display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; text-align: center;
    }
    .veil p { margin: 0; max-width: 46ch; }
    .small { font-size: 14px; opacity: .85; }
  `]
})
export class ClubItemPage implements OnInit {
  private commerce = inject(CommerceService);
  private route = inject(ActivatedRoute);
  private seo = inject(SeoService);
  private status = inject(HttpStatusService);

  readonly item = signal<ClubDetail | null>(null);
  readonly error = signal(false);
  readonly paragraphs = computed(() => (this.item()?.body ?? '').split(/\n{2,}/).map((p) => p.trim()).filter(Boolean));

  ngOnInit(): void {
    const slug = this.route.snapshot.paramMap.get('slug')!;
    this.commerce.clubItem(slug).subscribe({
      next: (res) => {
        this.item.set(res.data);
        // Описание видно всем, а закрытый текст в индекс не отдаём.
        this.seo.apply({ title: res.data.title, description: res.data.lead, image: res.data.cover?.url, path: `/club/${slug}`, noindex: true });
      },
      error: (err) => {
        this.error.set(true);
        // Код 404 для робота: «не найдено» не должно попадать в индекс.
        this.status.fromError(err);
      },
    });
  }
}
