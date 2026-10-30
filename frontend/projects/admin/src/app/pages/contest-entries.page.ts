import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';

import { ApiService } from '@shared/services/api.service';
import {
  Contest, ContestEntry, ENTRY_STATUS_LABELS, EntryStatus,
} from '@shared/models/api.models';
import { errorText, formatDate, plural } from '@shared/utils';
import { ToastService } from '../services/toast.service';

@Component({
    selector: 'adm-contest-entries',
    imports: [RouterLink, FormsModule],
    template: `
    <nav class="crumbs muted">
      <a routerLink="/contests">Конкурсы</a> /
      @if (contest(); as c) {
        <a [routerLink]="['/contests', c.id]">{{ c.title }}</a> /
      }
      <span>Работы</span>
    </nav>

    <header class="head">
      <div>
        <h1>Присланные работы</h1>
        <p class="muted">
          @if (contest(); as c) {
            {{ c.title }} · {{ c.phase_label }} · всего {{ c.entries_count }}
            {{ plural(c.entries_count, 'работа', 'работы', 'работ') }}
          } @else {
            Загружаем конкурс…
          }
        </p>
      </div>
    </header>

    <div class="note note-warn">
      Если отклонить работу, за которую уже голосовали, её голоса обнулятся и обратно не вернутся.
    </div>

    <div class="tabs" role="group" aria-label="Фильтр по статусу работ">
      @for (t of tabs; track t.value) {
        <button class="tab" type="button" [class.on]="tab === t.value"
                [attr.aria-pressed]="tab === t.value" (click)="setTab(t.value)">
          {{ t.label }}
        </button>
      }
    </div>

    @if (loading() && !rows().length) {
      <div class="loading"><div class="spinner"></div></div>
    } @else if (rows().length) {
      <div class="cards">
        @for (e of rows(); track e.id) {
          <article class="entry">
            <div class="shots">
              @for (m of e.media || []; track m.id) {
                @if (isVideo()) {
                  <video class="shot" [src]="m.url" controls preload="metadata"></video>
                } @else {
                  <img class="shot" [src]="m.url" [alt]="m.alt || e.title || 'Работа участника'">
                }
              } @empty {
                <div class="shot ph muted">Без файлов</div>
              }
            </div>

            <div class="body">
              <div class="row top">
                <h2>{{ e.title || 'Работа без названия' }}</h2>
                <span class="chip" [class]="chipClass(e.status)">{{ statusLabel(e.status) }}</span>
                @if (e.place) { <span class="chip chip-g">{{ e.place }} место</span> }
              </div>

              @if (e.body) { <p class="text">{{ e.body }}</p> }

              <p class="muted meta">
                {{ e.author?.name || 'Автор удалён' }}
                @if (e.author?.rating_label) { · {{ e.author?.rating_label }} }
                · {{ formatDate(e.created_at) || 'дата неизвестна' }}
                · голосов {{ e.votes_count }}
              </p>

              @if (e.reject_reason) {
                <p class="muted meta">Причина отклонения: {{ e.reject_reason }}</p>
              }

              <div class="row">
                @if (e.status !== 'approved') {
                  <button class="btn btn-green btn-sm" type="button" [disabled]="busy() === e.id"
                          (click)="approve(e)">Принять</button>
                }
                @if (e.status !== 'rejected') {
                  <button class="btn btn-ghost btn-sm" type="button" [disabled]="busy() === e.id"
                          (click)="askReject(e)">Отклонить</button>
                }
              </div>
            </div>
          </article>
        }
      </div>

      @if (page() < lastPage()) {
        <div class="more">
          <button class="btn btn-ghost" type="button" [disabled]="loading()" (click)="load(page() + 1)">
            Показать ещё
          </button>
        </div>
      }
    } @else {
      <div class="empty">
        <h3>{{ emptyTitle() }}</h3>
        <p class="muted">Работы появятся здесь, как только участники их пришлют.</p>
      </div>
    }

    <section class="panel">
      <h2>Победители</h2>
      <p class="muted weak">
        Места выбираются из принятых работ. Одна работа может занять только одно место,
        и на одном месте может стоять только один участник.
      </p>

      @if (approved().length) {
        <div class="places">
          @for (p of placeSlots; track p.place) {
            <label class="fl" [attr.for]="'place-' + p.place">{{ p.label }}
              <select [id]="'place-' + p.place" [name]="'place-' + p.place"
                      [ngModel]="places()[p.place]" (ngModelChange)="pick(p.place, $event)">
                <option [ngValue]="null">Не выбрано</option>
                @for (e of approved(); track e.id) {
                  <option [ngValue]="e.id">
                    {{ e.title || 'Работа без названия' }} — {{ e.author?.name || 'без автора' }}
                  </option>
                }
              </select>
            </label>
          }
        </div>

        <div class="row">
          <button class="btn btn-green btn-sm" type="button" [disabled]="savingWinners()"
                  (click)="saveWinners()">
            {{ savingWinners() ? 'Сохраняем…' : 'Сохранить места' }}
          </button>
          <button class="btn btn-ghost btn-sm" type="button" [disabled]="savingWinners()"
                  (click)="resetAsked.set(true)">Сбросить все места</button>
        </div>
      } @else {
        <p class="muted">Пока нет ни одной принятой работы — выбирать победителей не из чего.</p>
      }
    </section>

    @if (rejecting(); as e) {
      <div class="modal" role="dialog" aria-modal="true" aria-label="Отклонить работу">
        <div class="box">
          <h2>Отклонить работу</h2>
          <p class="muted">«{{ e.title || 'Работа без названия' }}» — автор увидит причину в своём профиле.</p>
          @if (e.votes_count) {
            <p class="note note-warn">
              У работы {{ e.votes_count }} {{ plural(e.votes_count, 'голос', 'голоса', 'голосов') }}.
              После отклонения они обнулятся.
            </p>
          }
          <label class="fl" for="reason">Причина
            <textarea id="reason" name="reason" rows="3" [(ngModel)]="reason"
                      placeholder="Например: на фото нет готового блюда"></textarea>
          </label>
          <div class="row end">
            <button class="btn btn-ghost btn-sm" type="button" (click)="rejecting.set(null)">Отмена</button>
            <button class="btn btn-primary btn-sm" type="button" (click)="confirmReject(e)">Отклонить</button>
          </div>
        </div>
      </div>
    }

    @if (resetAsked()) {
      <div class="modal" role="dialog" aria-modal="true" aria-label="Сбросить места">
        <div class="box">
          <h2>Сбросить все места</h2>
          <p class="muted">Призовые места снимутся со всех работ. Выбор придётся делать заново.</p>
          <div class="row end">
            <button class="btn btn-ghost btn-sm" type="button" (click)="resetAsked.set(false)">Отмена</button>
            <button class="btn btn-primary btn-sm" type="button" (click)="resetWinners()">Сбросить</button>
          </div>
        </div>
      </div>
    }
  `,
    styles: [`
    .crumbs { font-size: 13px; margin-bottom: 12px; }
    .head { margin-bottom: 16px; }
    h1 { font-size: 30px; }
    .head p { margin: 4px 0 0; font-size: 14px; }
    .tabs { display: flex; flex-wrap: wrap; gap: 6px; margin: 16px 0; padding-bottom: 12px; border-bottom: 1px solid var(--line); }
    .tab { min-height: 44px; padding: 0 16px; border: 0; border-radius: 999px; background: none; color: var(--muted); font-size: 14px; }
    .tab.on { background: var(--panel); color: #fff; font-weight: 700; }
    .cards { display: flex; flex-direction: column; gap: 16px; }
    .entry {
      display: flex; flex-wrap: wrap; gap: 18px; background: var(--surface);
      border: 1px solid var(--line); border-radius: 16px; padding: 16px;
    }
    .shots { flex: 0 1 240px; min-width: 0; display: flex; flex-wrap: wrap; gap: 8px; }
    .shot { width: 112px; height: 112px; object-fit: cover; border-radius: 12px; background: var(--sunken); }
    .ph { display: grid; place-items: center; font-size: 12px; text-align: center; }
    .body { flex: 999 1 280px; min-width: 0; display: flex; flex-direction: column; gap: 10px; }
    .top { align-items: center; gap: 10px; }
    .body h2 { font-family: var(--body); font-size: 18px; font-weight: 700; }
    .text { margin: 0; font-size: 14px; line-height: 1.6; white-space: pre-line; }
    .meta { margin: 0; font-size: 13px; }
    .panel {
      margin-top: 24px; background: var(--surface); border: 1px solid var(--line);
      border-radius: 16px; padding: 22px; display: flex; flex-direction: column; gap: 14px;
    }
    .panel h2 { font-family: var(--body); font-size: 18px; font-weight: 700; }
    .panel p { margin: 0; }
    .weak { font-weight: 400; font-size: 13px; }
    .places { display: flex; flex-wrap: wrap; gap: 14px; }
    .places label { flex: 1 1 220px; min-width: 0; }
    .more { display: flex; justify-content: center; margin-top: 18px; }
    .loading { display: grid; place-items: center; min-height: 30vh; }
    .end { justify-content: flex-end; }
    .modal {
      position: fixed; inset: 0; z-index: 50; background: rgba(12, 11, 8, .6);
      display: grid; place-items: center; padding: 20px;
    }
    .box {
      background: var(--surface); border-radius: 18px; padding: 24px;
      width: min(520px, 100%); display: flex; flex-direction: column; gap: 14px;
    }
    .box h2 { font-size: 22px; }
  `]
})
export class ContestEntriesPage implements OnInit {
  /** Номер запроса: ответ, отставший от нового отбора, игнорируем,
   *  иначе он перезапишет свежий список устаревшим. */
  private loadSeq = 0;

  private api = inject(ApiService);
  private route = inject(ActivatedRoute);
  private toasts = inject(ToastService);

  readonly tabs: { value: EntryStatus; label: string }[] = [
    { value: 'pending', label: 'Ждут проверки' },
    { value: 'approved', label: 'Принятые' },
    { value: 'rejected', label: 'Отклонённые' },
  ];

  readonly placeSlots = [
    { place: 1, label: 'Первое место' },
    { place: 2, label: 'Второе место' },
    { place: 3, label: 'Третье место' },
  ];

  tab: EntryStatus = 'pending';
  reason = '';

  readonly contestId = signal(0);
  readonly contest = signal<Contest | null>(null);
  readonly rows = signal<ContestEntry[]>([]);
  readonly approved = signal<ContestEntry[]>([]);
  readonly page = signal(1);
  readonly lastPage = signal(1);
  readonly loading = signal(true);
  readonly busy = signal<number | null>(null);
  readonly savingWinners = signal(false);
  readonly rejecting = signal<ContestEntry | null>(null);
  readonly resetAsked = signal(false);

  /** Выбранные места: номер места → id работы. */
  readonly places = signal<Record<number, number | null>>({ 1: null, 2: null, 3: null });

  readonly formatDate = formatDate;
  readonly plural = plural;

  ngOnInit(): void {
    this.contestId.set(Number(this.route.snapshot.paramMap.get('id')));
    this.loadContest();
    this.load();
    this.loadApproved();
  }

  /**
   * resetPlaces=false при обновлении после модерации: иначе ответ сервера
   * затёр бы ещё не сохранённый выбор мест.
   */
  private loadContest(resetPlaces = true): void {
    this.api.adminContest(this.contestId()).subscribe({
      next: (res) => {
        this.contest.set(res.data);
        if (resetPlaces) this.fillPlaces(res.data.winners ?? []);
      },
      error: (err) => this.toasts.error(errorText(err, 'Конкурс не найден или нет доступа.')),
    });
  }

  private fillPlaces(winners: ContestEntry[]): void {
    const next: Record<number, number | null> = { 1: null, 2: null, 3: null };
    for (const w of winners) {
      if (w.place === 1 || w.place === 2 || w.place === 3) next[w.place] = w.id;
    }
    this.places.set(next);
  }

  /** Список принятых работ нужен для выбора победителей независимо от открытой вкладки. */
  private loadApproved(): void {
    this.api.adminContestEntries(this.contestId(), 'approved').subscribe({
      next: (res) => this.approved.set(res.data),
      error: () => this.approved.set([]),
    });
  }

  isVideo(): boolean {
    return this.contest()?.kind === 'video';
  }

  statusLabel(status: EntryStatus): string {
    return ENTRY_STATUS_LABELS[status];
  }

  chipClass(status: EntryStatus): string {
    if (status === 'approved') return 'chip-g';
    if (status === 'rejected') return 'chip-b';
    return '';
  }

  emptyTitle(): string {
    if (this.tab === 'pending') return 'Непроверенных работ нет';
    if (this.tab === 'approved') return 'Принятых работ пока нет';
    return 'Отклонённых работ нет';
  }

  setTab(value: EntryStatus): void {
    this.tab = value;
    this.load();
  }

  load(page = 1): void {
    this.loading.set(true);
    const seq = ++this.loadSeq;

    this.api.adminContestEntries(this.contestId(), this.tab, page).subscribe({
      next: (res) => {
        if (seq !== this.loadSeq) return;

        this.rows.set(page === 1 ? res.data : [...this.rows(), ...res.data]);
        this.page.set(res.meta.current_page);
        this.lastPage.set(res.meta.last_page);
        this.loading.set(false);
      },
      error: (err) => {
        if (seq !== this.loadSeq) return;

        this.loading.set(false);
        this.toasts.error(errorText(err));
      },
    });
  }

  approve(e: ContestEntry): void {
    this.busy.set(e.id);
    this.api.moderateEntry(this.contestId(), e.id, 'approved').subscribe({
      next: () => {
        this.busy.set(null);
        this.toasts.show('Работа принята');
        this.refresh();
      },
      error: (err) => {
        this.busy.set(null);
        this.toasts.error(errorText(err));
      },
    });
  }

  askReject(e: ContestEntry): void {
    this.reason = '';
    this.rejecting.set(e);
  }

  confirmReject(e: ContestEntry): void {
    const reason = this.reason.trim();
    if (!reason) {
      // Бэкенд всё равно требует причину, но лучше сказать это до запроса.
      this.toasts.error('Напишите причину отклонения');
      return;
    }

    this.busy.set(e.id);
    this.api.moderateEntry(this.contestId(), e.id, 'rejected', reason).subscribe({
      next: () => {
        this.busy.set(null);
        this.rejecting.set(null);
        this.toasts.show('Работа отклонена');
        this.refresh();
      },
      error: (err) => {
        this.busy.set(null);
        this.toasts.error(errorText(err));
      },
    });
  }

  pick(place: number, entryId: number | null): void {
    this.places.update((current) => ({ ...current, [place]: entryId }));
  }

  saveWinners(): void {
    const current = this.places();
    const list = this.placeSlots
      .map((slot) => ({ entry_id: current[slot.place], place: slot.place }))
      .filter((row): row is { entry_id: number; place: number } => row.entry_id !== null);

    this.savingWinners.set(true);
    this.api.setContestWinners(this.contestId(), list).subscribe({
      next: (res) => {
        this.savingWinners.set(false);
        this.fillPlaces(res.data);
        this.toasts.show('Места сохранены');
        this.refresh();
      },
      error: (err) => {
        this.savingWinners.set(false);
        // Повтор участника на одном месте бэкенд объясняет сам.
        this.toasts.error(errorText(err));
      },
    });
  }

  resetWinners(): void {
    this.resetAsked.set(false);
    this.savingWinners.set(true);
    // Пустой список снимает все призовые места.
    this.api.setContestWinners(this.contestId(), []).subscribe({
      next: () => {
        this.savingWinners.set(false);
        this.places.set({ 1: null, 2: null, 3: null });
        this.toasts.show('Места сброшены');
        this.refresh();
      },
      error: (err) => {
        this.savingWinners.set(false);
        this.toasts.error(errorText(err));
      },
    });
  }

  private refresh(): void {
    this.load();
    this.loadApproved();
    this.loadContest(false);
  }
}
