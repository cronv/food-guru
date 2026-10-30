/** Мелкие общие функции для обоих приложений. */

/** Русские числительные: 1 рецепт, 2 рецепта, 5 рецептов. */
export function plural(n: number, one: string, few: string, many: string): string {
  const m10 = n % 10;
  const m100 = n % 100;
  if (m10 === 1 && m100 !== 11) return one;
  if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return few;
  return many;
}

export function pluralize(n: number, one: string, few: string, many: string): string {
  return `${n} ${plural(n, one, few, many)}`;
}

/**
 * Количество ингредиента на выбранное число порций.
 * В базе amount хранится на одну порцию, поэтому просто умножаем.
 */
export function scaleAmount(amount: number | null, servings: number): string {
  if (amount === null || amount === undefined) return '';
  const rounded = Math.round(amount * servings * 100) / 100;
  if (Math.abs(rounded - Math.round(rounded)) < 0.01) return String(Math.round(rounded));
  return String(rounded).replace('.', ',');
}

/** 26 октября 2026 */
export function formatDate(iso: string | null | undefined): string {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' });
}

/** 26 октября — без года, для свежих записей. */
export function formatShortDate(iso: string | null | undefined): string {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' });
}

/** Собирает текст ошибки Laravel в одну строку для показа человеку. */
export function errorText(err: unknown, fallback = 'Что-то пошло не так. Попробуйте ещё раз.'): string {
  const e = err as {
    status?: number;
    headers?: { get(name: string): string | null };
    error?: { message?: string; errors?: Record<string, string[]> };
  };

  // Laravel отвечает на лимит английским «Too Many Attempts.» — переводим и говорим, сколько ждать.
  if (e?.status === 429) {
    const seconds = Number(e.headers?.get('Retry-After'));
    const wait = seconds > 0
      ? (seconds < 120 ? `через ${pluralize(seconds, 'секунду', 'секунды', 'секунд')}` : `через ${pluralize(Math.ceil(seconds / 60), 'минуту', 'минуты', 'минут')}`)
      : 'чуть позже';
    return `Слишком много попыток подряд. Попробуйте ${wait}.`;
  }
  if (e?.status === 0) {
    return 'Сервер не отвечает. Проверьте интернет и попробуйте ещё раз.';
  }

  const payload = e?.error;
  if (payload?.errors) {
    const first = Object.values(payload.errors)[0];
    if (first && first.length) return first[0];
  }
  return payload?.message || fallback;
}
