import { HttpRequest } from '@angular/common/http';

import { isPublicApiRequest } from './transfer-cache';

/**
 * Ошибка здесь не видна глазами: вошедший читатель просто получит гостевую
 * версию страницы при прямой загрузке. Поэтому персональные адреса перечислены явно.
 */
describe('isPublicApiRequest', () => {
  const get = (path: string) => new HttpRequest('GET', `http://localhost:8000/api/v1/${path}`);

  it('общие разделы кэшируются', () => {
    for (const path of [
      'home', 'categories', 'categories/supy', 'tags', 'collections', 'collections/leto',
      'recipes', 'reels', 'recipes/borsch/related', 'recipes/borsch/reviews',
      'journal', 'journal/periods', 'journal/gayd-yanvar',
    ]) {
      expect(isPublicApiRequest(get(path))).withContext(path).toBeTrue();
    }
  });

  it('ответы, зависящие от читателя, не кэшируются', () => {
    for (const path of [
      'recipes/borsch', 'contests', 'contests/leto', 'contests/leto/entries',
      'academy', 'academy/nozh-i-doska', 'rating', 'me', 'me/favorites', 'me/rating',
      'journal/gayd-yanvar/download',
    ]) {
      expect(isPublicApiRequest(get(path))).withContext(path).toBeFalse();
    }
  });

  it('параметры запроса на решение не влияют', () => {
    const req = new HttpRequest('GET', 'http://localhost:8000/api/v1/recipes?page=2');
    expect(isPublicApiRequest(req)).toBeTrue();
  });

  it('адреса вне API не кэшируются', () => {
    expect(isPublicApiRequest(new HttpRequest('GET', 'http://localhost:8000/sanctum/csrf-cookie'))).toBeFalse();
  });
});
