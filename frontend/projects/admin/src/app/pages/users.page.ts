import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';

import { ApiService } from '@shared/services/api.service';
import { AuthService } from '@shared/services/auth.service';
import { ROLE_LABELS, User, UserRole } from '@shared/models/api.models';
import { errorText, formatShortDate } from '@shared/utils';
import { CommerceService } from '@shared/services/commerce.service';
import { WalletState, rub } from '@shared/models/commerce.models';
import { ToastService } from '../services/toast.service';

@Component({
    selector: 'adm-users',
    imports: [FormsModule],
    template: `
    <header class="head">
      <div>
        <h1>Пользователи</h1>
        <p class="muted">{{ total() }} аккаунтов</p>
      </div>
    </header>

    <div class="filters">
      <input type="search" [(ngModel)]="q" (keyup.enter)="load()" placeholder="Имя или email"
             aria-label="Поиск пользователей">
      <select [(ngModel)]="role" (change)="load()" aria-label="Фильтр по роли">
        <option value="">Все роли</option>
        @for (r of roleOptions; track r.value) { <option [value]="r.value">{{ r.label }}</option> }
      </select>
      <select [(ngModel)]="verified" (change)="load()" aria-label="Фильтр по статусу почты">
        <option value="">Любой статус почты</option>
        <option value="yes">Подтверждена</option>
        <option value="no">Не подтверждена</option>
      </select>
    </div>

    @if (loading()) {
      <div class="loading"><div class="spinner"></div></div>
    } @else {
      <div class="scroll">
        <table>
          <thead>
            <tr>
              <th>Пользователь</th>
              <th>Email</th>
              <th>Роль</th>
              <th>Почта</th>
              <th class="right">Действия</th>
            </tr>
          </thead>
          <tbody>
            @for (u of rows(); track u.id) {
              <tr [class.off]="!u.is_active">
                <td><strong>{{ u.name }}</strong></td>
                <td class="muted">{{ u.email }}</td>
                <td>
                  @if (canManage(u)) {
                    <select [ngModel]="u.role" (ngModelChange)="changeRole(u, $event)"
                            [attr.aria-label]="'Роль: ' + u.name">
                      @for (r of assignable(); track r.value) {
                        <option [value]="r.value">{{ r.label }}</option>
                      }
                    </select>
                  } @else {
                    <span class="chip">{{ u.role_label }}</span>
                  }
                </td>
                <td>
                  @if (u.email_verified) {
                    <span class="ok">Подтверждена</span>
                  } @else {
                    <span class="bad">Не подтверждена</span>
                    @if (canManage(u)) {
                      <button class="link" type="button" (click)="resend(u)">выслать снова</button>
                    }
                  }
                </td>
                <td class="right">
                  <div class="acts">
                    @if (isAdmin()) {
                      <button class="btn btn-ghost btn-sm" type="button" [class.on]="walletFor() === u.id"
                              [attr.aria-expanded]="walletFor() === u.id" (click)="toggleWallet(u)">Баланс</button>
                    }
                    @if (canManage(u)) {
                      <button class="btn btn-ghost btn-sm" type="button" (click)="toggleActive(u)">
                        {{ u.is_active ? 'Отключить' : 'Включить' }}
                      </button>
                    } @else if (!isAdmin()) {
                      <span class="muted small">—</span>
                    }
                  </div>
                </td>
              </tr>
              @if (walletFor() === u.id) {
                <tr class="wallet-row">
                  <td colspan="5">
                    <div class="wallet">
                      <!-- После правки старые данные видны, пока не придут свежие. -->
                      @if (wallet(); as w) {
                        <div class="w-head">
                          <span class="muted">Баланс {{ u.name }}</span>
                          <strong class="tnum bal">{{ rub(w.balance) }}</strong>
                        </div>

                        @if (w.transactions.length) {
                          <ul class="tx">
                            @for (t of w.transactions.slice(0, 10); track t.id) {
                              <li>
                                <span class="tnum amt" [class.plus]="t.amount > 0" [class.minus]="t.amount < 0">
                                  {{ t.amount > 0 ? '+' : '' }}{{ rub(t.amount) }}
                                </span>
                                <span class="tx-desc">{{ t.description }}</span>
                                <span class="muted tx-when">{{ when(t.created_at) }}</span>
                              </li>
                            }
                          </ul>
                        } @else {
                          <p class="muted small">Операций пока не было.</p>
                        }

                        <form class="adjust" (ngSubmit)="adjust(u)">
                          <label class="fl amount">Сумма, ₽
                            <input type="number" step="0.01" name="w-amount" [(ngModel)]="adjAmount"
                                   placeholder="500 или -200">
                          </label>
                          <label class="fl reason">Причина
                            <input type="text" name="w-reason" [(ngModel)]="adjReason" maxlength="255"
                                   placeholder="Например, компенсация за задержку заказа">
                          </label>
                          <button class="btn btn-green btn-sm" type="submit" [disabled]="adjusting()">
                            {{ adjusting() ? 'Сохраняем…' : 'Изменить баланс' }}
                          </button>
                        </form>
                        <p class="muted small">Плюс — начислить, минус — списать. Причина видна в истории операций.</p>
                        @if (adjError()) { <div class="note note-warn" role="alert">{{ adjError() }}</div> }
                      } @else {
                        <div class="spinner" aria-label="Загружаем баланс"></div>
                      }
                    </div>
                  </td>
                </tr>
              }
            }
          </tbody>
        </table>
      </div>

      @if (page() < lastPage()) {
        <div class="more">
          <button class="btn btn-ghost" type="button" (click)="load(page() + 1)">Показать ещё</button>
        </div>
      }

      <div class="note">
        Руководитель редакции распоряжается только пользователями и контент-менеджерами.
        Администраторов меняет администратор — иначе роль можно было бы повысить себе.
      </div>
    }
  `,
    styles: [`
    .head { margin-bottom: 18px; }
    h1 { font-size: 30px; }
    .head p { margin: 4px 0 0; font-size: 14px; }
    .filters { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; }
    .filters input { flex: 1 1 240px; min-width: 0; }
    .filters select { flex: 0 1 200px; }
    .right { text-align: right; }
    .ok { color: var(--green); font-weight: 600; }
    .bad { color: var(--brick-ink); font-weight: 600; }
    .link { border: 0; background: none; padding: 0; margin-left: 8px; color: var(--muted); text-decoration: underline; font-size: 13px; }
    .off { opacity: .55; }
    .small { font-size: 13px; }
    .more { display: flex; justify-content: center; margin-top: 18px; }
    .note { margin-top: 20px; }
    .loading { display: grid; place-items: center; min-height: 30vh; }
    td select { min-height: 38px; }
    .acts { display: flex; flex-wrap: wrap; gap: 6px; justify-content: flex-end; }
    .acts .on { border-color: var(--green); background: var(--green-soft); color: var(--green-ink); }
    .btn-sm { min-height: 44px; }
    .wallet-row td { background: var(--sunken); }
    /* Таблица на телефоне прокручивается вбок — панель баланса держим в видимой части. */
    .wallet {
      position: sticky; left: 0; display: flex; flex-direction: column; gap: 12px;
      width: min(720px, calc(100vw - 72px)); white-space: normal;
    }
    .w-head { display: flex; flex-wrap: wrap; align-items: baseline; gap: 10px; }
    .bal { font-size: 22px; }
    .tx { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; font-size: 14px; }
    .tx li { display: flex; flex-wrap: wrap; gap: 2px 12px; }
    .amt { flex: 0 0 110px; font-weight: 700; }
    .plus { color: var(--green); }
    .minus { color: var(--brick-ink); }
    .tx-desc { flex: 1 1 200px; min-width: 0; overflow-wrap: anywhere; }
    .tx-when { font-size: 12px; }
    .adjust { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
    .amount { flex: 1 1 130px; min-width: 0; }
    .reason { flex: 999 1 240px; min-width: 0; }
    .wallet .note { margin: 0; }
    .wallet p { margin: 0; }
  `]
})
export class UsersPage implements OnInit {
  /** Номер запроса: ответ, отставший от нового отбора, игнорируем,
   *  иначе он перезапишет свежий список устаревшим. */
  private loadSeq = 0;

  private api = inject(ApiService);
  private toasts = inject(ToastService);
  private auth = inject(AuthService);
  private shop = inject(CommerceService);

  /** Номер запроса кошелька: ответ по прежнему пользователю не должен лечь в панель нового. */
  private walletSeq = 0;

  readonly roleOptions = (Object.keys(ROLE_LABELS) as UserRole[]).map((value) => ({
    value,
    label: ROLE_LABELS[value],
  }));

  q = '';
  role = '';
  verified = '';

  readonly rows = signal<User[]>([]);
  readonly total = signal(0);
  readonly page = signal(1);
  readonly lastPage = signal(1);
  readonly loading = signal(true);

  /** Кошелёк: правка баланса — только администратору. */
  readonly walletFor = signal<number | null>(null);
  readonly wallet = signal<WalletState | null>(null);
  readonly walletLoading = signal(false);
  readonly adjusting = signal(false);
  readonly adjError = signal<string | null>(null);
  adjAmount: number | null = null;
  adjReason = '';

  readonly rub = rub;

  ngOnInit(): void {
    this.load();
  }

  load(page = 1): void {
    this.loading.set(true);
    const seq = ++this.loadSeq;

    this.api.adminUsers({
      q: this.q || undefined,
      role: this.role || undefined,
      verified: this.verified || undefined,
      page,
    }).subscribe({
      next: (res) => {
        if (seq !== this.loadSeq) return;

        this.rows.set(page === 1 ? res.data : [...this.rows(), ...res.data]);
        this.total.set(res.meta.total);
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

  /** Повторяет логику UserPolicy, чтобы не показывать заведомо запрещённое. */
  canManage(u: User): boolean {
    const me = this.auth.user();
    if (!me || me.id === u.id) return false;
    if (me.role === 'admin') return true;
    if (me.role !== 'editor_lead') return false;
    return u.role === 'user' || u.role === 'content_manager';
  }

  assignable(): { value: UserRole; label: string }[] {
    if (this.auth.atLeast('admin')) return this.roleOptions;
    return this.roleOptions.filter((r) => r.value === 'user' || r.value === 'content_manager');
  }

  changeRole(u: User, role: UserRole): void {
    const before = u.role;
    this.api.updateUser(u.id, { role }).subscribe({
      next: (res) => {
        this.rows.update((list) => list.map((x) => (x.id === u.id ? res.data : x)));
        this.toasts.show(`${u.name}: роль «${res.data.role_label}»`);
      },
      error: (err) => {
        u.role = before;
        this.toasts.error(errorText(err));
      },
    });
  }

  toggleActive(u: User): void {
    this.api.updateUser(u.id, { is_active: !u.is_active }).subscribe({
      next: (res) => {
        this.rows.update((list) => list.map((x) => (x.id === u.id ? res.data : x)));
        this.toasts.show(res.data.is_active ? 'Аккаунт включён' : 'Аккаунт отключён');
      },
      error: (err) => this.toasts.error(errorText(err)),
    });
  }

  isAdmin(): boolean {
    return this.auth.atLeast('admin');
  }

  when(iso: string | null): string {
    return formatShortDate(iso);
  }

  toggleWallet(u: User): void {
    if (this.walletFor() === u.id) {
      this.walletFor.set(null);
      return;
    }
    this.walletFor.set(u.id);
    this.wallet.set(null);
    this.adjAmount = null;
    this.adjReason = '';
    this.adjError.set(null);
    this.loadWallet(u.id);
  }

  private loadWallet(userId: number): void {
    this.walletLoading.set(true);
    const seq = ++this.walletSeq;

    this.shop.userWallet(userId).subscribe({
      next: (res) => {
        if (seq !== this.walletSeq) return;
        this.wallet.set(res.data);
        this.walletLoading.set(false);
      },
      error: (err) => {
        if (seq !== this.walletSeq) return;
        this.walletLoading.set(false);
        this.walletFor.set(null);
        this.toasts.error(errorText(err));
      },
    });
  }

  adjust(u: User): void {
    this.adjError.set(null);
    const amount = Math.round(Number(this.adjAmount) * 100);
    const reason = this.adjReason.trim();

    if (!this.adjAmount || !Number.isFinite(amount) || amount === 0) {
      this.adjError.set('Укажите сумму: плюс — начислить, минус — списать.');
      return;
    }
    if (reason.length < 5) {
      this.adjError.set('Опишите причину — не короче 5 символов.');
      return;
    }

    this.adjusting.set(true);
    this.shop.adjustWallet(u.id, amount, reason).subscribe({
      next: (res) => {
        this.adjusting.set(false);
        this.adjAmount = null;
        this.adjReason = '';
        this.toasts.show(`Баланс ${u.name}: ${rub(res.data.balance)}`);
        // История операций пополнилась — перечитываем её целиком.
        this.loadWallet(u.id);
      },
      error: (err) => {
        this.adjusting.set(false);
        // 402: списание увело бы баланс в минус — показываем текст сервера у формы.
        if ((err as { status?: number }).status === 402) {
          this.adjError.set(errorText(err, 'На балансе не хватает денег для такого списания.'));
          return;
        }
        this.toasts.error(errorText(err));
      },
    });
  }

  resend(u: User): void {
    this.api.resendUserVerification(u.id).subscribe({
      next: () => this.toasts.show('Письмо отправлено'),
      error: (err) => this.toasts.error(errorText(err)),
    });
  }
}
