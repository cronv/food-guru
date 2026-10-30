/* Кошелёк, книги, магазин и закрытая академия. Все суммы — в копейках (990 ₽ = 99000). */

export interface WalletTransaction {
  id: number;
  /** Со знаком: пополнение и возврат — плюс, покупка — минус. */
  amount: number;
  balance_after: number;
  type: 'topup' | 'purchase' | 'refund' | 'adjustment';
  description: string;
  created_at: string | null;
}

export interface WalletState {
  balance: number;
  transactions: WalletTransaction[];
}

export interface PaymentMethod {
  code: string;
  label: string;
}

export interface PaymentMethods {
  methods: PaymentMethod[];
  min: number;
  max: number;
  test_mode: boolean;
  /** Платёжный шлюз настроен. Пока нет — пополнения нет, форма не показывается. */
  available: boolean;
}

export interface PaymentInfo {
  id: string;
  amount: number;
  method: string;
  method_label: string;
  status: 'pending' | 'succeeded' | 'canceled';
  provider: string;
  confirmation_url: string | null;
  paid_at: string | null;
  created_at: string | null;
  /** Баланс после — приходит в ответах статуса платежа. */
  balance?: number;
}

/** Ответ 402: на балансе не хватает денег. */
export interface InsufficientFunds {
  code: 'insufficient_funds';
  message: string;
  balance: number;
  needed: number;
  shortfall: number;
}

export interface Cover {
  url: string;
  alt: string | null;
}

export interface BookCard {
  id: number;
  slug: string;
  title: string;
  author_name: string | null;
  year?: number | null;
  country?: string | null;
  lead: string | null;
  price: number;
  is_free: boolean;
  in_subscription: boolean;
  chapters_count: number;
  cover: Cover | null;
  owned: boolean;
  can_read: boolean;
  can_download: boolean;
}

export interface BookDetail extends BookCard {
  description: string | null;
  chapters: Array<{ position: number; title: string; is_preview: boolean; readable: boolean }>;
}

export interface BookChapterText {
  book: { slug: string; title: string };
  position: number;
  title: string;
  body: string;
  prev: number | null;
  next: number | null;
}

export interface PriceRange {
  min: number;
  max: number;
}

export interface ProductFilters {
  q?: string;
  category?: string[];
  material?: string[];
  country?: string[];
  color?: string[];
  /** Копейки. */
  price_min?: number;
  price_max?: number;
  in_stock?: boolean;
  sort?: 'popular' | 'price_asc' | 'price_desc' | 'new' | 'title' | 'discount';
}

export interface ProductFacets {
  categories: string[];
  materials: string[];
  countries: string[];
  colors: string[];
  price: PriceRange;
}

export interface BookFilters {
  q?: string;
  status?: 'all' | 'free' | 'paid' | 'subscription' | 'mine';
  price_min?: number;
  price_max?: number;
  year_from?: number;
  year_to?: number;
  author?: string[];
  country?: string[];
  sort?: 'new' | 'price_asc' | 'price_desc' | 'title' | 'year_desc' | 'year_asc';
}

export interface BookFacets {
  authors: string[];
  countries: string[];
  years: { min: number | null; max: number | null };
  price: PriceRange;
}

export interface Product {
  id: number;
  slug: string;
  title: string;
  category: string | null;
  material?: string | null;
  country?: string | null;
  color?: string | null;
  lead: string | null;
  price: number;
  old_price: number | null;
  in_stock: boolean;
  /** Остаток показывается, только когда товара мало (до 5 шт.). */
  stock_left: number | null;
  cover: Cover | null;
  description?: string | null;
  sku?: string | null;
}

export interface DeliveryMethod {
  code: 'pickup' | 'courier' | 'post';
  label: string;
  price: number;
  needs_address: boolean;
}

export type OrderStatus = 'paid' | 'assembling' | 'shipped' | 'delivered' | 'canceled';

export interface Order {
  number: string;
  status: OrderStatus;
  status_label: string;
  can_cancel: boolean;
  next_statuses: OrderStatus[];
  items_total: number;
  delivery_method: string;
  delivery_label: string;
  delivery_price: number;
  total: number;
  recipient_name: string;
  phone: string;
  city: string | null;
  address: string | null;
  comment: string | null;
  items: Array<{ product_id: number | null; title: string; price: number; quantity: number; total: number }>;
  created_at: string | null;
  shipped_at: string | null;
  delivered_at: string | null;
  canceled_at: string | null;
  /** Только в админке. */
  id?: number;
  user?: { id: number; name: string; email: string } | null;
}

export interface OrderRequest {
  items: Array<{ product_id: number; quantity: number }>;
  delivery_method: DeliveryMethod['code'];
  recipient_name: string;
  phone: string;
  city?: string;
  address?: string;
  comment?: string;
}

export interface SubscriptionPlan {
  code: string;
  title: string;
  months: number;
  price: number;
  per_month: number;
}

export interface SubscriptionState {
  active: boolean;
  ends_at: string | null;
}

export type ClubKind = 'video' | 'masterclass' | 'recipe' | 'material';

export interface ClubCard {
  slug: string;
  kind: ClubKind;
  kind_label: string;
  title: string;
  lead: string | null;
  chef_name: string | null;
  duration_minutes: number | null;
  has_video: boolean;
  cover: Cover | null;
  unlocked: boolean;
}

export interface ClubDetail extends ClubCard {
  body?: string | null;
  recipe?: { ingredients?: string[]; steps?: string[] } | null;
  /** Временная подписанная ссылка, живёт 2 часа. */
  video_url?: string | null;
}

export interface ClubIndex {
  data: ClubCard[];
  meta: {
    kinds: Array<{ code: ClubKind; label: string }>;
    plans: SubscriptionPlan[];
    subscription: SubscriptionState;
    books: Array<{ slug: string; title: string; author_name: string | null; cover: Cover | null }>;
  };
}

/** Ошибка 402 «не хватает денег» — или null, если ошибка другая. */
export function insufficientFunds(err: unknown): InsufficientFunds | null {
  const e = err as { status?: number; error?: InsufficientFunds };
  return e?.status === 402 && e.error?.code === 'insufficient_funds' ? e.error : null;
}

/** 990 ₽ / 1 234,50 ₽ — копейки показываем, только если они есть. */
export function rub(kopecks: number): string {
  const whole = Number.isInteger(kopecks / 100);
  return new Intl.NumberFormat('ru-RU', {
    style: 'currency',
    currency: 'RUB',
    minimumFractionDigits: whole ? 0 : 2,
    maximumFractionDigits: 2,
  }).format(kopecks / 100);
}
