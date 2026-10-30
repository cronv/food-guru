import { HttpRequest } from '@angular/common/http';

/**
 * Какие ответы сервер передаёт браузеру вместе с HTML при гидратации.
 *
 * Сервер ходит в API без cookie читателя, поэтому все его ответы — гостевые.
 * Если браузер переиспользует гостевой ответ там, где ответ зависит от человека,
 * вошедший читатель при прямой загрузке увидит чужую версию: занятие без ссылки
 * на запись, рецепт не в избранном, конкурс без своего голоса.
 *
 * Поэтому список разрешающий, а не запрещающий: новый маршрут API в кэш
 * не попадёт, пока его не добавят сюда сознательно. Не кэшируются
 * `recipes/{slug}` (is_favorite), конкурсы и академия (блок viewer),
 * рейтинг (is_me) и всё под `me`.
 */
const PUBLIC_PATHS = [
  /^home$/,
  /^categories(\/[^/]+)?$/,
  /^tags$/,
  /^collections(\/[^/]+)?$/,
  /^recipes$/,
  /^reels$/,
  /^recipes\/[^/]+\/(related|reviews)$/,
  /^journal$/,
  /^journal\/[^/]+$/,
];

export function isPublicApiRequest(req: HttpRequest<unknown>): boolean {
  const match = req.url.match(/\/api\/v1\/([^?#]*)/);
  if (!match) return false;

  const path = match[1].replace(/\/$/, '');
  return PUBLIC_PATHS.some((pattern) => pattern.test(path));
}
