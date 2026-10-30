import { Component, ElementRef, OnInit, PLATFORM_ID, computed, inject, signal } from '@angular/core';
import { isPlatformBrowser } from '@angular/common';
import { ActivatedRoute, Router, RouterLink, RouterLinkActive } from '@angular/router';
import { FormsModule } from '@angular/forms';
import { forkJoin, of } from 'rxjs';
import { catchError } from 'rxjs/operators';

import { ApiService } from '@shared/services/api.service';
import { AuthService } from '@shared/services/auth.service';
import { CommerceService } from '@shared/services/commerce.service';
import { PasswordFormComponent } from '@shared/components/password-form.component';
import { AcademyCard, ContestEntry, MyRating, MyRecipeRow, RecipeCard, User } from '@shared/models/api.models';
import { BookCard, Order, SubscriptionState, rub } from '@shared/models/commerce.models';
import { errorText, formatDate, pluralize } from '@shared/utils';
import { BreadcrumbsComponent, Crumb } from '../components/breadcrumbs.component';
import { RecipeCardComponent } from '../components/recipe-card.component';
import { SeoService } from '../services/seo.service';
import { ToastService } from '../services/toast.service';

type Section = 'overview' | 'recipes' | 'favorites' | 'purchases' | 'activity' | 'rating' | 'settings';

const SECTIONS: { id: Section; title: string }[] = [
  { id: 'overview', title: 'Обзор' },
  { id: 'recipes', title: 'Мои рецепты' },
  { id: 'favorites', title: 'Избранное' },
  { id: 'purchases', title: 'Покупки' },
  { id: 'activity', title: 'Конкурсы и эфиры' },
  { id: 'rating', title: 'Рейтинг' },
  { id: 'settings', title: 'Профиль и безопасность' },
];

/**
 * Личный кабинет по разделам: у каждого свой адрес (/account/purchases),
 * чтобы на него можно было сослаться из письма или справки.
 * Данные грузятся один раз при входе в кабинет — разделы переключаются без ожидания.
 */
@Component({
    selector: 'app-account',
    imports: [RouterLink, RouterLinkActive, FormsModule, RecipeCardComponent, PasswordFormComponent, BreadcrumbsComponent],
    template: `
    <div class="wrap band">
      <app-breadcrumbs [items]="crumbs()" />
      @if (auth.isAuthenticated()) {
        <header class="who">
          <span class="avatar" aria-hidden="true">{{ initials() }}</span>
          <div>
            <h1>{{ auth.user()?.name }}</h1>
            <div class="row ident">
              <span class="muted">{{ auth.user()?.email }}</span>
              @if (auth.isVerified()) {
                <span class="chip chip-g">Почта подтверждена</span>
              } @else {
                <span class="chip chip-b">Почта не подтверждена</span>
              }
              @if (rating(); as r) { <span class="chip">{{ r.level_label }}</span> }
              @if (auth.user()?.is_staff) { <span class="chip">{{ auth.user()?.role_label }}</span> }
            </div>
          </div>
        </header>

        @if (!auth.isVerified()) {
          <div class="note note-warn block">
            Подтвердите адрес по ссылке из письма — до этого недоступны избранное, отзывы и покупки.
            <button class="btn btn-sm btn-ghost resend" type="button" (click)="resend()">Выслать письмо заново</button>
          </div>
        }

        <div class="layout">
          <nav class="side" aria-label="Разделы кабинета">
            @for (s of sections; track s.id) {
              <a class="item" [routerLink]="['/account', s.id]" routerLinkActive="active"
                 [routerLinkActiveOptions]="{ exact: true }" [class.active]="section() === s.id">{{ s.title }}</a>
            }
            <span class="sep" aria-hidden="true"></span>
            <a class="item ext" routerLink="/cookbook">Рецептосохранялка</a>
            <a class="item ext" routerLink="/wallet">Кошелёк</a>
            <a class="item ext" routerLink="/orders">Мои заказы</a>
            <a class="item ext" routerLink="/help">Помощь</a>
            <button class="item out" type="button" (click)="logout()">Выйти</button>
          </nav>

          <section class="content">
            @switch (section()) {
              @case ('overview') {
                <div class="tiles">
                  <a class="tile" routerLink="/wallet">
                    <span class="k">Баланс</span>
                    <span class="v tnum">{{ balance() !== null ? rub(balance()!) : '—' }}</span>
                    <span class="muted s">Пополнить →</span>
                  </a>
                  <a class="tile" routerLink="/club">
                    <span class="k">Закрытая академия</span>
                    <span class="v">{{ sub()?.active ? 'до ' + date(sub()!.ends_at) : 'не оформлена' }}</span>
                    <span class="muted s">{{ sub()?.active ? 'Открыть материалы →' : 'Посмотреть тарифы →' }}</span>
                  </a>
                  <a class="tile" [routerLink]="['/account', 'rating']">
                    <span class="k">Рейтинг</span>
                    <span class="v tnum">{{ rating() ? points(rating()!.points) : '—' }}</span>
                    <span class="muted s">{{ rating()?.position ? 'место № ' + rating()!.position : 'Как набрать баллы →' }}</span>
                  </a>
                  <a class="tile" [routerLink]="['/account', 'purchases']">
                    <span class="k">Покупки</span>
                    <span class="v tnum">{{ books().length }} кн. · {{ orders().length }} зак.</span>
                    <span class="muted s">Книги и заказы →</span>
                  </a>
                </div>

                <section class="steps">
                  <div class="sec-head">
                    <h2>Первые шаги</h2>
                    <span class="muted tnum">{{ doneCount() }} из {{ checklist().length }}</span>
                  </div>
                  <ol class="check">
                    @for (c of checklist(); track c.title) {
                      <li [class.done]="c.done">
                        <span class="mark" aria-hidden="true">{{ c.done ? '✓' : '' }}</span>
                        <div>
                          <strong>{{ c.title }}</strong>
                          <p class="muted">{{ c.hint }}</p>
                        </div>
                        @if (!c.done && c.link) {
                          <a class="btn btn-ghost btn-sm" [routerLink]="c.link" [fragment]="c.fragment">{{ c.action }}</a>
                        }
                      </li>
                    }
                  </ol>
                </section>

                <section class="guides">
                  <h2>Как это работает</h2>
                  <div class="glist">
                    @for (g of guides; track g.id) {
                      <a class="guide" routerLink="/help" [fragment]="g.id">
                        <strong>{{ g.title }}</strong><span class="muted">{{ g.text }}</span>
                      </a>
                    }
                  </div>
                </section>
              }

              @case ('recipes') {
                <div class="sec-head">
                  <h2>Мои рецепты</h2>
                  <a class="btn btn-green btn-sm" routerLink="/account/recipes/new">+ Добавить рецепт</a>
                </div>
                <p class="muted intro">Черновик → на модерации → опубликован. Редакция проверяет граммовки и шаги; если что-то не так, вернёт рецепт с комментарием. <a routerLink="/help" fragment="recipe">Подробнее</a></p>
                @if (myRecipes().length) {
                  <ul class="rows">
                    @for (r of myRecipes(); track r.id) {
                      <li class="line my">
                        <a class="thumb" [routerLink]="['/account/recipes', r.id]">@if (r.cover) { <img [src]="r.cover.url" alt=""> }</a>
                        <div class="my-body">
                          <a class="strong" [routerLink]="['/account/recipes', r.id]">{{ r.title }}</a>
                          @if (r.moderation_note) { <span class="muted sm">Поправить: {{ r.moderation_note }}</span> }
                        </div>
                        <span class="chip" [class.chip-g]="r.status === 'published'" [class.chip-b]="r.status === 'rejected'">{{ r.status_label }}</span>
                        @if (r.status === 'published') {
                          <a class="btn btn-ghost btn-sm" [routerLink]="['/recipes', r.slug]">На сайте</a>
                        } @else {
                          <a class="btn btn-ghost btn-sm" [routerLink]="['/account/recipes', r.id]">{{ r.can_edit ? 'Править' : 'Открыть' }}</a>
                        }
                      </li>
                    }
                  </ul>
                } @else {
                  <div class="empty">
                    <h3>Своих рецептов пока нет</h3>
                    <p class="muted">Поделитесь фирменным блюдом: после проверки редакцией оно появится в каталоге, а вы получите 25 баллов рейтинга.</p>
                    <a class="btn btn-green" routerLink="/account/recipes/new">Добавить рецепт</a>
                  </div>
                }
              }

              @case ('favorites') {
                <div class="sec-head"><h2>Избранное · {{ favTotal() }}</h2></div>
                @if (favorites().length) {
                  <div class="grid">@for (r of favorites(); track r.slug) { <app-recipe-card [recipe]="r" /> }</div>
                } @else {
                  <div class="empty">
                    <h3>Пока пусто</h3>
                    <p class="muted">Откройте рецепт и нажмите «В избранное». А то, что собираетесь готовить, удобнее держать в <a routerLink="/cookbook">рецептосохранялке</a>.</p>
                    <a class="btn btn-ghost" routerLink="/recipes">К каталогу</a>
                  </div>
                }
              }

              @case ('purchases') {
                <div class="sec-head"><h2>Мои книги</h2><a class="btn btn-ghost btn-sm" routerLink="/books">Все книги →</a></div>
                @if (books().length) {
                  <div class="shelf">
                    @for (b of books(); track b.slug) {
                      <a class="book" [routerLink]="['/books', b.slug]">
                        <div class="cover">@if (b.cover) { <img [src]="b.cover.url" [alt]="b.cover.alt || b.title" loading="lazy"> }</div>
                        <span class="bt">{{ b.title }}</span>
                      </a>
                    }
                  </div>
                } @else {
                  <p class="muted">Купленных книг нет. Бесплатные книги читаются без покупки.</p>
                }

                <div class="sec-head part"><h2>Последние заказы</h2><a class="btn btn-ghost btn-sm" routerLink="/orders">Все заказы →</a></div>
                @if (orders().length) {
                  <ul class="rows">
                    @for (o of orders().slice(0, 5); track o.number) {
                      <li class="line">
                        <strong class="tnum">№ {{ o.number }}</strong>
                        <span class="chip">{{ o.status_label }}</span>
                        <span class="muted">{{ date(o.created_at) }}</span>
                        <span class="tnum sum">{{ rub(o.total) }}</span>
                      </li>
                    }
                  </ul>
                } @else {
                  <p class="muted">Заказов пока нет. <a routerLink="/shop">В магазин</a></p>
                }

                <div class="sec-head part"><h2>Подписка</h2></div>
                <p>
                  @if (sub()?.active) {
                    Закрытая академия оплачена до <strong>{{ date(sub()!.ends_at) }}</strong>. <a routerLink="/club">Открыть материалы</a>
                  } @else {
                    Подписки нет. <a routerLink="/club">Что внутри закрытой академии</a>
                  }
                </p>
              }

              @case ('activity') {
                <div class="sec-head"><h2>Мои работы на конкурсах</h2></div>
                @if (entries().length) {
                  <div class="rows">
                    @for (e of entries(); track e.id) {
                      <article class="line col">
                        <div class="row head">
                          <span class="chip" [class.chip-g]="e.status === 'approved'" [class.chip-b]="e.status === 'rejected'">{{ e.status_label }}</span>
                          @if (e.contest; as c) { <a class="strong" [routerLink]="['/contests', c.slug]">{{ c.title }}</a> }
                          <span class="muted when">{{ date(e.created_at) }}</span>
                        </div>
                        @if (e.title) { <p class="what">{{ e.title }}</p> }
                        <p class="muted sm tnum">{{ votes(e.votes_count) }}</p>
                        @if (e.status === 'rejected' && e.reject_reason) { <p class="muted sm">Причина: {{ e.reject_reason }}</p> }
                      </article>
                    }
                  </div>
                } @else {
                  <div class="empty">
                    <h3>Работ пока нет</h3>
                    <p class="muted">Выберите конкурс, где открыт приём, и отправьте фото, видео или рецепт.</p>
                    <a class="btn btn-ghost" routerLink="/contests">К конкурсам</a>
                  </div>
                }

                <div class="sec-head part"><h2>Мои записи на эфиры</h2></div>
                @if (sessions().length) {
                  <div class="rows">
                    @for (s of sessions(); track s.slug) {
                      <article class="line col">
                        <div class="row head">
                          <span class="chip chip-g">{{ s.format_label }}</span>
                          @if (s.is_live_now) { <span class="chip chip-b">Идёт сейчас</span> }
                          <a class="strong" [routerLink]="['/academy', s.slug]">{{ s.title }}</a>
                        </div>
                        <p class="muted sm">
                          @if (s.starts_at) { {{ dateTime(s.starts_at) }} }
                          @if (s.starts_at && s.host_name) { · }
                          @if (s.host_name) { ведёт {{ s.host_name }} }
                        </p>
                      </article>
                    }
                  </div>
                } @else {
                  <div class="empty">
                    <h3>Записей пока нет</h3>
                    <p class="muted">Посмотрите расписание и запишитесь — ссылка на эфир появится на его странице.</p>
                    <a class="btn btn-ghost" routerLink="/academy">К расписанию</a>
                  </div>
                }
              }

              @case ('rating') {
                @if (rating(); as r) {
                  <div class="sec-head">
                    <h2>Рейтинг</h2>
                    <a class="btn btn-ghost btn-sm" routerLink="/rating">Таблица лидеров →</a>
                  </div>
                  <div class="rcards">
                    <div class="box"><span class="k">Баллы</span><span class="v tnum">{{ r.points }}</span></div>
                    <div class="box"><span class="k">Уровень</span><span class="v">{{ r.level_label }}</span></div>
                    <div class="box"><span class="k">Серия заходов</span><span class="v tnum">{{ days(r.streak_days) }}</span></div>
                    <div class="box"><span class="k">Место</span><span class="v tnum">{{ r.position !== null ? '№ ' + r.position : 'пока нет' }}</span></div>
                  </div>

                  <div class="progress">
                    <div class="plabel">
                      @if (r.next_level_label && r.points_to_next !== null) {
                        <span>До уровня «{{ r.next_level_label }}» осталось <strong class="tnum">{{ points(r.points_to_next) }}</strong></span>
                      } @else {
                        <span>Это верхний уровень шкалы</span>
                      }
                      <span class="muted tnum">{{ percent(r.progress) }}%</span>
                    </div>
                    <div class="track" role="progressbar" [attr.aria-valuenow]="percent(r.progress)" aria-valuemin="0" aria-valuemax="100"
                         [attr.aria-label]="'Прогресс до следующего уровня: ' + percent(r.progress) + '%'">
                      <div class="fill" [style.width.%]="percent(r.progress)"></div>
                    </div>
                  </div>

                  @if (r.breakdown.length) {
                    <h3 class="part">Из чего сложились баллы</h3>
                    <table class="tbl">
                      <tbody>
                        @for (b of r.breakdown; track b.source) {
                          <tr><td>{{ b.label }}</td><td class="muted tnum">{{ b.count }} {{ b.unit }}</td><td class="tnum">+{{ b.points }}</td></tr>
                        }
                      </tbody>
                    </table>
                  }
                  <p class="muted"><a routerLink="/help" fragment="rating">Как считается рейтинг</a> — за что дают баллы и какие есть уровни.</p>
                } @else {
                  <div class="loading"><div class="spinner"></div></div>
                }
              }

              @case ('settings') {
                <div class="profile" id="profile">
                  <h2>Профиль</h2>
                  <label class="fl" for="pname">Имя
                    <input id="pname" type="text" name="pname" autocomplete="name" [(ngModel)]="name">
                  </label>
                  <label class="fl" for="pmail">Почта
                    <input id="pmail" type="email" name="pmail" autocomplete="email" [(ngModel)]="email">
                    <span class="muted weak">Смена адреса потребует подтверждения по новой ссылке.</span>
                  </label>
                  @if (emailChanging() && auth.user()?.has_password) {
                    <label class="fl" for="pcur">Текущий пароль
                      <input id="pcur" type="password" name="pcur" autocomplete="current-password" [(ngModel)]="currentPassword">
                      <span class="muted weak">Нужен, чтобы сменить почту: так аккаунт не уведут с чужого открытого браузера.</span>
                    </label>
                  }
                  <button class="btn btn-green save" type="button" [disabled]="saving()" (click)="save()">
                    {{ saving() ? 'Сохраняем…' : 'Сохранить' }}
                  </button>
                </div>

                <div class="profile" id="password">
                  <h2>{{ auth.user()?.has_password ? 'Сменить пароль' : 'Создать пароль' }}</h2>
                  @if (!auth.user()?.has_password) {
                    <p class="muted">Пароля пока нет: вы входите по ссылке из письма. Задайте пароль, чтобы входить по почте и паролю.</p>
                  }
                  <fg-password-form [requireCurrent]="auth.user()?.has_password === true"
                    [submitLabel]="auth.user()?.has_password ? 'Сменить пароль' : 'Создать пароль'"
                    (saved)="onPasswordSaved($event)" />
                </div>
              }
            }
          </section>
        </div>
      } @else if (auth.loaded()) {
        <div class="empty">
          <h1>Нужен вход</h1>
          <p class="muted">Войдите или создайте аккаунт, чтобы сохранять рецепты, покупать книги и следить за рейтингом.</p>
          <div class="row">
            <a class="btn btn-green" routerLink="/login">Войти</a>
            <a class="btn btn-ghost" routerLink="/register">Создать аккаунт</a>
          </div>
        </div>
      } @else {
        <div class="loading"><div class="spinner"></div></div>
      }
    </div>
  `,
    styles: [`
    .who { display: flex; align-items: center; gap: 16px; }
    .avatar {
      flex: 0 0 auto; width: 60px; height: 60px; border-radius: 999px; display: grid; place-items: center;
      background: var(--green); color: var(--on-accent); font-weight: 700; font-size: 20px;
    }
    h1 { font-size: clamp(26px, 4vw, 36px); }
    .ident { align-items: center; margin-top: 6px; }
    .block { margin-top: 18px; }
    .resend { margin-left: 10px; }
    .layout { display: flex; flex-wrap: wrap; gap: 26px; align-items: flex-start; margin-top: 24px; }
    .side {
      flex: 1 1 220px; min-width: 0; background: var(--surface); border: 1px solid var(--line);
      border-radius: 18px; padding: 10px; display: flex; flex-direction: column; gap: 2px;
      position: sticky; top: 84px;
    }
    .item {
      display: flex; align-items: center; min-height: 42px; padding: 0 14px; border-radius: 10px;
      border: 0; background: none; text-align: left; font: inherit; color: var(--ink); cursor: pointer;
    }
    .item:hover { background: var(--sunken); }
    .item.active { background: var(--green-soft); color: var(--green-ink); font-weight: 700; }
    .sep { height: 1px; background: var(--line); margin: 6px 8px; }
    .out { color: var(--brick); font-weight: 600; }
    .content { flex: 999 1 520px; min-width: 0; }
    .loading { display: grid; place-items: center; min-height: 200px; }
    .tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 12px; }
    .tile {
      display: flex; flex-direction: column; gap: 4px; padding: 16px; border-radius: 16px;
      background: var(--surface); border: 1px solid var(--line); color: var(--ink);
    }
    .tile:hover { border-color: var(--green); }
    .k { font-size: 11px; letter-spacing: .1em; text-transform: uppercase; color: var(--muted); }
    .v { font-size: 20px; font-weight: 700; }
    .s { font-size: 13px; }
    .steps, .guides { margin-top: 32px; }
    .check { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
    .check li {
      display: grid; grid-template-columns: 28px 1fr auto; gap: 12px; align-items: center;
      padding: 12px 14px; border-radius: 14px; background: var(--surface); border: 1px solid var(--line);
    }
    .check li.done { opacity: .7; }
    .check p { margin: 2px 0 0; font-size: 13px; }
    .mark {
      width: 26px; height: 26px; border-radius: 999px; border: 2px solid var(--line);
      display: grid; place-items: center; font-weight: 800; color: var(--on-accent);
    }
    .done .mark { background: var(--green); border-color: var(--green); }
    @media (max-width: 520px) { .check li { grid-template-columns: 28px 1fr; } .check .btn { grid-column: 2; justify-self: start; } }
    .glist { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px; }
    .guide {
      display: flex; flex-direction: column; gap: 4px; padding: 14px; border-radius: 14px;
      border: 1px solid var(--line); color: var(--ink); font-size: 14px;
    }
    .guide:hover { border-color: var(--green); background: var(--surface); }
    .shelf { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 16px; }
    .book { display: flex; flex-direction: column; gap: 6px; color: var(--ink); }
    .cover { aspect-ratio: 2 / 3; border-radius: 6px 12px 12px 6px; overflow: hidden; background: var(--sunken); }
    .cover img { width: 100%; height: 100%; object-fit: cover; }
    .bt { font-weight: 700; font-size: 14px; }
    .part { margin-top: 32px; }
    .rows { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
    .line {
      display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 12px 16px;
      background: var(--surface); border: 1px solid var(--line); border-radius: 14px;
    }
    .line.col { flex-direction: column; align-items: stretch; }
    .line .sum { margin-left: auto; font-weight: 700; }
    .intro { margin: 0 0 14px; font-size: 14px; }
    .my { flex-wrap: nowrap; }
    .thumb { flex: 0 0 auto; width: 56px; height: 42px; border-radius: 8px; overflow: hidden; background: var(--sunken); }
    .thumb img { width: 100%; height: 100%; object-fit: cover; }
    .my-body { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
    .my-body .strong { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    @media (max-width: 520px) { .my { flex-wrap: wrap; } .my-body { flex-basis: calc(100% - 70px); } }
    .line .head { align-items: center; }
    .strong { font-weight: 700; }
    .when { font-size: 13px; }
    .what { margin: 4px 0 0; font-weight: 600; }
    .sm { margin: 4px 0 0; font-size: 13px; }
    .rcards { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); }
    .box { background: var(--surface); border: 1px solid var(--line); border-radius: 16px; padding: 14px; display: flex; flex-direction: column; gap: 4px; }
    .progress { margin-top: 14px; display: flex; flex-direction: column; gap: 8px; max-width: 560px; }
    .plabel { display: flex; flex-wrap: wrap; gap: 10px; justify-content: space-between; font-size: 14px; }
    .track { height: 10px; border-radius: 999px; background: var(--sunken); overflow: hidden; }
    .fill { height: 100%; background: var(--green); border-radius: 999px; }
    .tbl { width: 100%; max-width: 560px; border-collapse: collapse; margin: 8px 0 12px; }
    .tbl td { padding: 8px 10px; border-bottom: 1px solid var(--line); }
    .tbl td:last-child { text-align: right; font-weight: 700; }
    h3 { font-family: var(--body); font-size: 17px; font-weight: 700; }
    .profile {
      margin-bottom: 24px; background: var(--surface); border: 1px solid var(--line);
      border-radius: 18px; padding: 22px; display: flex; flex-direction: column; gap: 14px; max-width: 460px;
    }
    .profile h2 { font-family: var(--body); font-size: 19px; font-weight: 700; }
    .weak { font-weight: 400; }
    .save { align-self: flex-start; }

    /*
     * Телефон: вертикальное меню из 11 пунктов занимало весь первый экран, и до
     * содержимого раздела приходилось листать. Теперь это полоса разделов,
     * которая прокручивается вбок и прилипает под шапкой; ссылки на кошелёк,
     * заказы и помощь — в нижнем меню «Ещё», поэтому здесь их нет.
     */
    @media (max-width: 760px) {
      .who { gap: 12px; }
      .avatar { width: 48px; height: 48px; font-size: 17px; }
      /* Сетка, а не flex-колонка с переносом: там ширина бралась по самому широкому
         элементу — по всей полосе разделов, — и страница уезжала вбок на 850px. */
      .layout { display: grid; grid-template-columns: minmax(0, 1fr); gap: 16px; margin-top: 16px; }
      .side {
        flex: none; flex-direction: row; gap: 6px; padding: 8px; border-radius: 14px;
        overflow-x: auto; scrollbar-width: none; top: 64px; z-index: 5;
        scroll-snap-type: x proximity;
        /* Затухание справа: видно, что полосу можно листать дальше. */
        -webkit-mask-image: linear-gradient(90deg, #000 85%, transparent);
        mask-image: linear-gradient(90deg, #000 85%, transparent);
      }
      .side::-webkit-scrollbar { display: none; }
      .item { flex: 0 0 auto; min-height: 40px; white-space: nowrap; border-radius: 999px; scroll-snap-align: start; }
      .sep, .side .ext { display: none; }
      .content { flex: none; }
      .tiles { grid-template-columns: 1fr 1fr; }
      .v { font-size: 17px; }
    }
  `]
})
export class AccountPage implements OnInit {
  private api = inject(ApiService);
  private commerce = inject(CommerceService);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private seo = inject(SeoService);
  private toasts = inject(ToastService);
  private platformId = inject(PLATFORM_ID);
  private host = inject(ElementRef<HTMLElement>);
  auth = inject(AuthService);
  readonly rub = rub;
  readonly sections = SECTIONS;

  readonly section = signal<Section>('overview');

  /** Главная / Личный кабинет / Раздел. На обзоре кабинет — последняя крошка. */
  readonly crumbs = computed<Crumb[]>(() => {
    const base: Crumb[] = [{ label: 'Главная', link: '/' }];
    if (this.section() === 'overview') return [...base, { label: 'Личный кабинет' }];
    const title = SECTIONS.find((s) => s.id === this.section())?.title ?? '';
    return [...base, { label: 'Личный кабинет', link: '/account' }, { label: title }];
  });

  readonly favorites = signal<RecipeCard[]>([]);
  readonly favTotal = signal(0);
  readonly rating = signal<MyRating | null>(null);
  readonly entries = signal<ContestEntry[]>([]);
  readonly sessions = signal<AcademyCard[]>([]);
  readonly balance = signal<number | null>(null);
  readonly sub = signal<SubscriptionState | null>(null);
  readonly books = signal<BookCard[]>([]);
  readonly orders = signal<Order[]>([]);
  readonly cookbookCount = signal(0);
  readonly myRecipes = signal<MyRecipeRow[]>([]);
  readonly saving = signal(false);

  readonly guides = [
    { id: 'recipe', title: 'Как добавить свой рецепт', text: 'Редактор, модерация и баллы' },
    { id: 'rating', title: 'Как считается рейтинг', text: 'За что дают баллы и уровни' },
    { id: 'club', title: 'Закрытая академия', text: 'Подписка, мастер-классы, книги' },
    { id: 'wallet', title: 'Кошелёк и оплата', text: 'Пополнение и возвраты' },
    { id: 'shop', title: 'Магазин и заказы', text: 'Доставка и отмена заказа' },
    { id: 'books', title: 'Книги', text: 'Покупка, читалка и PDF' },
  ];

  /** Чек-лист новичка: каждый пункт — реальное состояние аккаунта, а не галочка «прочитал». */
  readonly checklist = computed(() => {
    const u = this.auth.user();
    return [
      { title: 'Подтвердить почту', hint: 'Без этого не работают избранное, отзывы и покупки.', done: this.auth.isVerified(), link: null, fragment: undefined, action: '' },
      { title: 'Задать пароль', hint: 'Чтобы входить без письма.', done: !!u?.has_password, link: '/account/settings', fragment: 'password', action: 'Задать' },
      { title: 'Сохранить рецепт в рецептосохранялку', hint: 'Закладка на странице рецепта — ваша кулинарная книга.', done: this.cookbookCount() > 0, link: '/recipes', fragment: undefined, action: 'К рецептам' },
      { title: 'Добавить что-то в избранное', hint: 'Сердечко на странице рецепта.', done: this.favTotal() > 0, link: '/recipes', fragment: undefined, action: 'К рецептам' },
      { title: 'Пополнить кошелёк', hint: 'Книги, товары и подписка оплачиваются с баланса.', done: (this.balance() ?? 0) > 0 || this.books().length > 0 || this.orders().length > 0, link: '/wallet', fragment: undefined, action: 'Пополнить' },
      { title: 'Заглянуть в закрытую академию', hint: 'Мастер-классы шефов и ресторанные рецепты.', done: !!this.sub()?.active, link: '/club', fragment: undefined, action: 'Посмотреть' },
    ];
  });
  readonly doneCount = computed(() => this.checklist().filter((c) => c.done).length);

  name = '';
  email = '';
  currentPassword = '';

  emailChanging(): boolean {
    return this.email.trim().toLowerCase() !== (this.auth.user()?.email ?? '').toLowerCase();
  }

  ngOnInit(): void {
    this.seo.apply({ title: 'Личный кабинет', path: '/account', noindex: true });

    this.route.paramMap.subscribe((p) => {
      const s = p.get('section') as Section | null;
      // Старые ссылки (#password) и неизвестные разделы ведут на понятное место.
      if (s && !SECTIONS.some((x) => x.id === s)) {
        this.router.navigate(['/account']);
        return;
      }
      this.section.set(s ?? 'overview');
      this.revealActive();
    });

    const start = () => {
      const user = this.auth.user();
      if (!user) return;
      this.name = user.name;
      this.email = user.email;
      // Меню разделов появляется только после загрузки профиля — тогда и подвинуть.
      this.revealActive();

      // Ошибку каждого источника глушим: до подтверждения почты части разделов просто нет.
      const safe = <T>(o: import('rxjs').Observable<T>) => o.pipe(catchError(() => of(null)));

      safe(this.api.myRating()).subscribe((r) => this.rating.set(r?.data ?? null));
      if (!this.auth.isVerified()) return;

      forkJoin({
        fav: safe(this.api.favorites()),
        entries: safe(this.api.myContestEntries()),
        academy: safe(this.api.myAcademy()),
        wallet: safe(this.commerce.wallet()),
        club: safe(this.commerce.club()),
        books: safe(this.commerce.books('mine')),
        orders: safe(this.commerce.myOrders()),
        cookbook: safe(this.api.cookbookRecipes()),
        mine: safe(this.api.myRecipes()),
      }).subscribe((d) => {
        this.myRecipes.set(d.mine?.data ?? []);
        this.favorites.set(d.fav?.data ?? []);
        this.favTotal.set(d.fav?.meta.total ?? 0);
        this.entries.set(d.entries?.data ?? []);
        this.sessions.set(d.academy?.data ?? []);
        this.balance.set(d.wallet?.data.balance ?? null);
        this.sub.set(d.club?.meta.subscription ?? null);
        this.books.set(d.books?.data ?? []);
        this.orders.set(d.orders?.data ?? []);
        this.cookbookCount.set(d.cookbook?.meta.total ?? 0);
      });
    };
    if (this.auth.loaded()) start(); else this.auth.restore().subscribe(start);
  }

  /** На телефоне меню разделов прокручивается вбок — подвинуть его так, чтобы текущий был виден. */
  private revealActive(): void {
    if (!isPlatformBrowser(this.platformId)) return;
    setTimeout(() => {
      const el = this.host.nativeElement.querySelector('.side .item.active') as HTMLElement | null;
      const side = el?.parentElement;
      if (!el || !side || side.scrollWidth <= side.clientWidth) return;
      side.scrollTo({ left: el.offsetLeft - 8, behavior: 'smooth' });
    }, 50);
  }

  initials(): string {
    return (this.auth.user()?.name ?? '?').split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase();
  }

  onPasswordSaved(user: User): void {
    this.auth.setUser(user);
    this.toasts.show('Пароль сохранён');
  }

  percent(progress: number): number {
    return Math.round(Math.min(1, Math.max(0, progress)) * 100);
  }

  points(n: number): string {
    return pluralize(n, 'балл', 'балла', 'баллов');
  }

  days(n: number): string {
    return pluralize(n, 'день', 'дня', 'дней');
  }

  votes(n: number): string {
    return pluralize(n, 'голос', 'голоса', 'голосов');
  }

  date(iso: string | null): string {
    return formatDate(iso);
  }

  /** 3 ноября, 19:00 — в часовом поясе читателя. */
  dateTime(iso: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    return d.toLocaleString('ru-RU', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' });
  }

  save(): void {
    this.saving.set(true);
    const payload: { name: string; email: string; current_password?: string } = {
      name: this.name.trim(),
      email: this.email.trim().toLowerCase(),
    };
    if (this.emailChanging() && this.currentPassword) payload.current_password = this.currentPassword;

    this.api.updateMe(payload).subscribe({
      next: (res) => {
        this.auth.setUser(res.data);
        this.saving.set(false);
        this.currentPassword = '';
        this.toasts.show(res.data.pending_email ? 'Проверьте новый адрес — мы отправили письмо со ссылкой' : 'Сохранено');
      },
      error: (err) => {
        this.saving.set(false);
        this.toasts.error(errorText(err));
      },
    });
  }

  resend(): void {
    const email = this.auth.user()?.pending_email || this.auth.user()?.email;
    if (!email) return;
    this.api.resend(email).subscribe({
      next: () => this.toasts.show('Письмо отправлено заново'),
      error: (err) => this.toasts.error(errorText(err)),
    });
  }

  logout(): void {
    this.auth.logout().subscribe({
      next: () => {
        this.toasts.show('Сессия закрыта');
        this.router.navigate(['/']);
      },
      error: () => this.toasts.error('Не удалось выйти'),
    });
  }
}
