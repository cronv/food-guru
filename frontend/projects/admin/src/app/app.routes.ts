import { Routes } from '@angular/router';

import { staffGuard } from './guards';

export const routes: Routes = [
  {
    path: 'login',
    loadComponent: () => import('./pages/login.page').then((m) => m.LoginPage),
    title: 'Вход в админку',
  },
  {
    path: 'denied',
    loadComponent: () => import('./pages/denied.page').then((m) => m.DeniedPage),
    title: 'Нет доступа',
  },
  {
    path: '',
    loadComponent: () => import('./components/shell.component').then((m) => m.ShellComponent),
    canActivate: [staffGuard('content_manager')],
    children: [
      {
        path: '',
        loadComponent: () => import('./pages/dashboard.page').then((m) => m.DashboardPage),
        title: 'Обзор',
      },
      {
        path: 'recipes',
        loadComponent: () => import('./pages/recipes.page').then((m) => m.RecipesPage),
        title: 'Рецепты',
      },
      {
        path: 'recipes/new',
        loadComponent: () => import('./pages/recipe-edit.page').then((m) => m.RecipeEditPage),
        title: 'Новый рецепт',
      },
      {
        path: 'recipes/:id',
        loadComponent: () => import('./pages/recipe-edit.page').then((m) => m.RecipeEditPage),
        title: 'Редактор рецепта',
      },
      {
        path: 'contests',
        loadComponent: () => import('./pages/contests.page').then((m) => m.ContestsPage),
        title: 'Конкурсы',
      },
      {
        path: 'contests/new',
        loadComponent: () => import('./pages/contest-edit.page').then((m) => m.ContestEditPage),
        title: 'Новый конкурс',
      },
      {
        // Стоит после contests/new, иначе «new» попало бы в :id.
        path: 'contests/:id',
        loadComponent: () => import('./pages/contest-edit.page').then((m) => m.ContestEditPage),
        title: 'Редактор конкурса',
      },
      {
        path: 'contests/:id/entries',
        loadComponent: () => import('./pages/contest-entries.page').then((m) => m.ContestEntriesPage),
        title: 'Работы конкурса',
      },
      {
        path: 'academy',
        loadComponent: () => import('./pages/academy.page').then((m) => m.AcademyPage),
        title: 'Академия',
      },
      {
        path: 'academy/new',
        loadComponent: () => import('./pages/academy-edit.page').then((m) => m.AcademyEditPage),
        title: 'Новое занятие',
      },
      {
        path: 'academy/:id',
        loadComponent: () => import('./pages/academy-edit.page').then((m) => m.AcademyEditPage),
        title: 'Редактор занятия',
      },
      {
        // Персональные данные записавшихся: бэкенд отдаёт их от руководителя редакции.
        path: 'academy/:id/registrations',
        loadComponent: () => import('./pages/academy-registrations.page').then((m) => m.AcademyRegistrationsPage),
        canActivate: [staffGuard('editor_lead')],
        title: 'Записавшиеся на занятие',
      },
      {
        path: 'journal',
        loadComponent: () => import('./pages/journal.page').then((m) => m.JournalPage),
        title: 'Журнал',
      },
      {
        path: 'journal/new',
        loadComponent: () => import('./pages/journal-edit.page').then((m) => m.JournalEditPage),
        title: 'Новый выпуск',
      },
      {
        // Стоит после journal/new, иначе «new» попало бы в :id.
        path: 'journal/:id',
        loadComponent: () => import('./pages/journal-edit.page').then((m) => m.JournalEditPage),
        title: 'Редактор выпуска',
      },
      {
        path: 'products',
        loadComponent: () => import('./pages/products.page').then((m) => m.ProductsPage),
        title: 'Магазин',
      },
      {
        path: 'products/new',
        loadComponent: () => import('./pages/product-edit.page').then((m) => m.ProductEditPage),
        title: 'Новый товар',
      },
      {
        // Стоит после products/new, иначе «new» попало бы в :id.
        path: 'products/:id',
        loadComponent: () => import('./pages/product-edit.page').then((m) => m.ProductEditPage),
        title: 'Редактор товара',
      },
      {
        // Персональные данные покупателей и возвраты денег: от руководителя редакции.
        path: 'orders',
        loadComponent: () => import('./pages/orders.page').then((m) => m.OrdersPage),
        canActivate: [staffGuard('editor_lead')],
        title: 'Заказы',
      },
      {
        path: 'books',
        loadComponent: () => import('./pages/books.page').then((m) => m.BooksPage),
        title: 'Книги',
      },
      {
        path: 'books/new',
        loadComponent: () => import('./pages/book-edit.page').then((m) => m.BookEditPage),
        title: 'Новая книга',
      },
      {
        path: 'books/:id',
        loadComponent: () => import('./pages/book-edit.page').then((m) => m.BookEditPage),
        title: 'Редактор книги',
      },
      {
        path: 'club',
        loadComponent: () => import('./pages/club.page').then((m) => m.ClubPage),
        title: 'Закрытая академия',
      },
      {
        path: 'club/new',
        loadComponent: () => import('./pages/club-edit.page').then((m) => m.ClubEditPage),
        title: 'Новый материал',
      },
      {
        path: 'club/:id',
        loadComponent: () => import('./pages/club-edit.page').then((m) => m.ClubEditPage),
        title: 'Редактор материала',
      },
      {
        path: 'users',
        loadComponent: () => import('./pages/users.page').then((m) => m.UsersPage),
        canActivate: [staffGuard('editor_lead')],
        title: 'Пользователи',
      },
      {
        path: 'reports',
        loadComponent: () => import('./pages/reports.page').then((m) => m.ReportsPage),
        canActivate: [staffGuard('editor_lead')],
        title: 'Жалобы',
      },
      {
        path: 'activity',
        loadComponent: () => import('./pages/activity.page').then((m) => m.ActivityPage),
        canActivate: [staffGuard('admin')],
        title: 'Журнал действий',
      },
    ],
  },
  { path: '**', redirectTo: '' },
];
