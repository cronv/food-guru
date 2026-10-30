import { Routes } from '@angular/router';

export const routes: Routes = [
  {
    path: '',
    loadComponent: () => import('./pages/home.page').then((m) => m.HomePage),
    title: 'Food Guru — рецепты, которые получаются с первого раза',
  },
  {
    path: 'recipes',
    loadComponent: () => import('./pages/catalog.page').then((m) => m.CatalogPage),
  },
  {
    path: 'recipes/:slug',
    loadComponent: () => import('./pages/recipe.page').then((m) => m.RecipePage),
  },
  {
    path: 'cookbook',
    loadComponent: () => import('./pages/cookbook.page').then((m) => m.CookbookPage),
  },
  /* кошелёк */
  { path: 'wallet', loadComponent: () => import('./pages/wallet.page').then((m) => m.WalletPage) },
  { path: 'wallet/pay/:id', loadComponent: () => import('./pages/payment.page').then((m) => m.PaymentPage) },
  /* книги */
  { path: 'books', loadComponent: () => import('./pages/books.page').then((m) => m.BooksPage) },
  { path: 'books/:slug', loadComponent: () => import('./pages/book.page').then((m) => m.BookPage) },
  { path: 'books/:slug/read/:position', loadComponent: () => import('./pages/book-reader.page').then((m) => m.BookReaderPage) },
  /* магазин */
  { path: 'shop', loadComponent: () => import('./pages/shop.page').then((m) => m.ShopPage) },
  { path: 'shop/:slug', loadComponent: () => import('./pages/product.page').then((m) => m.ProductPage) },
  { path: 'cart', loadComponent: () => import('./pages/cart.page').then((m) => m.CartPage) },
  { path: 'orders', loadComponent: () => import('./pages/orders.page').then((m) => m.OrdersPage) },
  /* закрытая академия */
  { path: 'club', loadComponent: () => import('./pages/club.page').then((m) => m.ClubPage) },
  { path: 'club/:slug', loadComponent: () => import('./pages/club-item.page').then((m) => m.ClubItemPage) },
  {
    path: 'reels',
    loadComponent: () => import('./pages/reels.page').then((m) => m.ReelsPage),
  },
  {
    path: 'categories/:slug',
    loadComponent: () => import('./pages/category.page').then((m) => m.CategoryPage),
  },
  {
    path: 'collections',
    loadComponent: () => import('./pages/collections.page').then((m) => m.CollectionsPage),
  },
  {
    path: 'collections/:slug',
    loadComponent: () => import('./pages/collection.page').then((m) => m.CollectionPage),
  },
  {
    path: 'contests',
    loadComponent: () => import('./pages/contests.page').then((m) => m.ContestsPage),
    // Та же строка, что соберёт SeoService: роутер ставит заголовок последним.
    title: 'Конкурсы — Food Guru',
  },
  {
    // Заголовок страницы конкурса ставит SeoService — он знает название.
    path: 'contests/:slug',
    loadComponent: () => import('./pages/contest.page').then((m) => m.ContestPage),
  },
  {
    path: 'academy',
    loadComponent: () => import('./pages/academy.page').then((m) => m.AcademyPage),
    title: 'Кулинарная академия — Food Guru',
  },
  {
    path: 'academy/:slug',
    loadComponent: () => import('./pages/academy-session.page').then((m) => m.AcademySessionPage),
  },
  {
    path: 'journal',
    loadComponent: () => import('./pages/journal.page').then((m) => m.JournalPage),
    title: 'Журнал — Food Guru',
  },
  {
    // Заголовок страницы выпуска ставит SeoService — он знает название.
    path: 'journal/:slug',
    loadComponent: () => import('./pages/journal-issue.page').then((m) => m.JournalIssuePage),
  },
  {
    path: 'rating',
    loadComponent: () => import('./pages/rating.page').then((m) => m.RatingPage),
    title: 'Таблица лидеров — Food Guru',
  },
  {
    path: 'register',
    loadComponent: () => import('./pages/auth.page').then((m) => m.AuthPage),
  },
  {
    path: 'login',
    loadComponent: () => import('./pages/auth.page').then((m) => m.AuthPage),
    data: { mode: 'login' },
  },
  {
    // Ссылка из письма подтверждения.
    path: 'verify/:token',
    loadComponent: () => import('./pages/verify.page').then((m) => m.VerifyPage),
  },
  {
    // Ссылка для входа из письма.
    path: 'login/:token',
    loadComponent: () => import('./pages/verify.page').then((m) => m.VerifyPage),
    data: { mode: 'login' },
  },
  {
    path: 'account',
    loadComponent: () => import('./pages/account.page').then((m) => m.AccountPage),
  },
  /* редактор своего рецепта: new — новый, число — существующий */
  { path: 'account/recipes/new', loadComponent: () => import('./pages/my-recipe-edit.page').then((m) => m.MyRecipeEditPage) },
  { path: 'account/recipes/:id', loadComponent: () => import('./pages/my-recipe-edit.page').then((m) => m.MyRecipeEditPage) },
  {
    // Разделы кабинета: overview, recipes, favorites, purchases, activity, rating, settings.
    path: 'account/:section',
    loadComponent: () => import('./pages/account.page').then((m) => m.AccountPage),
  },
  { path: 'help', loadComponent: () => import('./pages/help.page').then((m) => m.HelpPage) },
  {
    path: '**',
    loadComponent: () => import('./pages/not-found.page').then((m) => m.NotFoundPage),
  },
];
