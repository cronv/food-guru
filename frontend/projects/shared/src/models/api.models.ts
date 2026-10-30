/** Типы ответов API. Один источник правды для сайта и админки. */

export type RecipeStatus = 'draft' | 'moderation' | 'published' | 'rejected' | 'archived';
export type Difficulty = 'easy' | 'medium' | 'hard';
export type UserRole = 'user' | 'content_manager' | 'editor_lead' | 'admin';

export interface Media {
  id: number;
  url: string;
  width: number | null;
  height: number | null;
  alt: string | null;
  caption?: string | null;
}

export interface TagRef {
  id?: number;
  slug: string;
  title: string;
}

export interface CategoryRef {
  id?: number;
  slug: string;
  title: string;
}

export interface AuthorRef {
  id: number;
  name: string;
  role_label?: string;
}

export interface RecipeCard {
  id: number;
  slug: string;
  title: string;
  lead: string | null;
  time_minutes: number | null;
  servings: number;
  difficulty: Difficulty;
  status: RecipeStatus;
  is_vegetarian: boolean;
  is_gluten_free: boolean;
  calories: number | null;
  published_at: string | null;
  cover?: Media | null;
  /** Вертикальное видео рецепта; null, если ролика нет. */
  reel_url?: string | null;
  category?: CategoryRef | null;
  author?: AuthorRef;
  tags?: TagRef[];
  reviews_count?: number;
  rating?: number | null;
  /** Сколько человек сохранили рецепт в рецептосохранялку. */
  saves_count?: number;
}

export interface Ingredient {
  id: number;
  name: string;
  amount: number | null;
  unit: string | null;
}

export interface Step {
  id: number;
  position: number;
  body: string;
  /** Короткая подсказка: температура, время. */
  hint?: string | null;
  /** Вкладки шага. Пустое поле — вкладка показывает «пока нет». */
  tip?: string | null;
  substitution?: string | null;
  chef_secret?: string | null;
  media: Media | null;
}

export type Reaction = 'like' | 'dislike';

/** Ответ на лайк или дизлайк: счётчики после изменения и выбор читателя. */
export interface ReactionState {
  likes_count: number;
  dislikes_count: number;
  my_reaction: Reaction | null;
}

export type ReportReason = 'error' | 'amounts' | 'copyright' | 'offensive' | 'spam' | 'other';

export type ReportStatus = 'open' | 'resolved' | 'rejected';

/** Жалоба читателя в админке. */
export interface RecipeReportRow {
  id: number;
  reason: ReportReason;
  reason_label: string;
  message: string | null;
  status: ReportStatus;
  created_at: string | null;
  resolved_at: string | null;
  recipe: { id: number; slug: string; title: string } | null;
  author: { id: number; name: string; email: string } | null;
  resolver: string | null;
}

export interface Recipe extends RecipeCard {
  og_description: string | null;
  updated_at: string | null;
  nutrition: {
    calories: number | null;
    protein: number | null;
    fat: number | null;
    carbs: number | null;
  };
  moderation_note?: string | null;
  gallery?: Media[];
  ingredients?: Ingredient[];
  steps?: Step[];
  is_favorite?: boolean;
  /** Рецепт лежит в рецептосохранялке читателя (только для вошедшего). */
  in_cookbook?: boolean;
  /** Поля ниже появятся, когда их начнёт отдавать сервер; до этого блоки скрыты. */
  views_count?: number;
  likes_count?: number;
  dislikes_count?: number;
  my_reaction?: Reaction | null;
}

export interface Review {
  id: number;
  rating: number;
  body: string;
  status: string;
  created_at: string | null;
  author?: AuthorRef;
  media?: Media[];
  recipe?: { slug: string; title: string };
}

export interface User {
  id: number;
  name: string;
  email: string;
  pending_email: string | null;
  role: UserRole;
  role_label: string;
  email_verified: boolean;
  /** Пароль задан. Без него войти можно только по ссылке из письма. */
  has_password: boolean;
  is_staff: boolean;
  is_active: boolean;
  created_at: string | null;
  rating: {
    points: number;
    level: RatingLevel;
    level_label: string;
    next_level: RatingLevel | null;
    next_level_label: string | null;
    points_to_next: number | null;
    progress: number;
    streak_days: number;
    streak_best: number;
    visits_total: number;
  };
}

export interface Category extends CategoryRef {
  description?: string | null;
  recipes_count?: number;
  children?: CategoryRef[];
}

export interface CollectionCard {
  slug: string;
  title: string;
  lead?: string | null;
  recipes_count: number;
  cover?: string | null;
}

export interface CollectionDetail extends CollectionCard {
  recipes: RecipeCard[];
}

export interface HomeCollection {
  slug: string;
  title: string;
  lead: string | null;
  recipes_count: number;
  saves_total: number;
  cover: { url: string; alt: string | null } | null;
}

export interface TopCook {
  id: number;
  name: string;
  rating_points: number;
  rating_label: string;
  recipes_count: number;
  reviews_count: number;
}

/** Рецепт читателя в кабинете: статус модерации и что поправить. */
export interface MyRecipeRow {
  id: number;
  slug: string;
  title: string;
  status: 'draft' | 'moderation' | 'published' | 'rejected' | 'archived';
  status_label: string;
  /** Есть, только когда редакция вернула рецепт. */
  moderation_note: string | null;
  can_edit: boolean;
  cover: { url: string; alt: string | null } | null;
  updated_at: string | null;
  published_at: string | null;
}

/** Своя запись в рецептосохранялке: рецепт по ссылке или записанный от руки. */
export interface CookbookNote {
  id: number;
  title: string;
  url: string | null;
  body: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface HomeData {
  hero: RecipeCard | null;
  /** Популярное на этой неделе: до 8 рецептов. */
  popular: RecipeCard[];
  reels: RecipeCard[];
  /** Самые популярные подборки — по сохранениям их рецептов. */
  collections: HomeCollection[];
  best: RecipeCard[];
  /** Рейтинг по добавлению в рецептосохранялку. */
  most_saved: RecipeCard[];
  top_cooks: TopCook[];
  categories: CategoryRef[];
}

export interface AdminStats {
  published: number;
  published_week: number;
  moderation: number;
  moderation_stale: number;
  drafts: number;
  rejected: number;
  users_total: number;
  users_week: number;
  verified_share: number | null;
  unverified: number;
  reviews_week: number;
  queue: RecipeCard[];
  contests_live: number;
  contest_entries_pending: number;
  academy_upcoming: number;
  academy_drafts: number;
  journal_published: number;
  journal_drafts: number;
}

export interface ActivityRow {
  id: number;
  action: string;
  subject_type: string | null;
  subject_id: number | null;
  payload: Record<string, unknown> | null;
  created_at: string | null;
  actor: { id: number; name: string } | null;
}

/* ---------- конкурсы ---------- */

export type PublishStatus = 'draft' | 'moderation' | 'published' | 'archived';
export type ContestKind = 'photo' | 'video' | 'recipe';
export type ContestPhase = 'announced' | 'submissions' | 'voting' | 'judging' | 'finished';
export type EntryStatus = 'pending' | 'approved' | 'rejected';

export interface ContestCard {
  id: number;
  slug: string;
  title: string;
  lead: string | null;
  kind: ContestKind;
  kind_label: string;
  status: PublishStatus;
  phase: ContestPhase;
  phase_label: string;
  is_archive: boolean;
  prize: string | null;
  entries_count: number;
  votes_count: number;
  submissions_open_at: string | null;
  submissions_close_at: string | null;
  voting_open_at: string | null;
  voting_close_at: string | null;
  results_at: string | null;
  /** Ближайшая дата, к которой стоит показать обратный отсчёт. */
  deadline_at: string | null;
  published_at: string | null;
  cover?: Media | null;
}

export interface ContestEntry {
  id: number;
  title: string | null;
  body: string | null;
  status: EntryStatus;
  status_label: string;
  votes_count: number;
  /** 1, 2, 3 — призовое место; null — без места. */
  place: number | null;
  created_at: string | null;
  reject_reason?: string | null;
  author?: AuthorRef & { rating_level?: RatingLevel; rating_label?: string };
  media?: Media[];
  contest?: { slug: string; title: string };
}

export interface Contest extends ContestCard {
  description: string | null;
  rules: string | null;
  phase_hint: string;
  max_entries_per_user: number;
  accepts_entries: boolean;
  accepts_votes: boolean;
  moderation_note?: string | null;
  author?: AuthorRef;
  winners?: ContestEntry[];
  /** Что доступно именно этому посетителю. */
  viewer: {
    can_submit: boolean;
    can_vote: boolean;
    entries_left: number;
    voted_entry_id: number | null;
    my_entries: ContestEntry[];
  };
}

/* ---------- кулинарная академия ---------- */

export type AcademyFormat = 'webinar' | 'stream' | 'lesson';
export type AcademyLevel = 'basic' | 'medium' | 'advanced';

export interface AcademyCard {
  id: number;
  slug: string;
  title: string;
  lead: string | null;
  format: AcademyFormat;
  format_label: string;
  level: AcademyLevel;
  level_label: string;
  status: PublishStatus;
  host_name: string | null;
  host_title: string | null;
  starts_at: string | null;
  ends_at: string | null;
  duration_minutes: number | null;
  is_live_now: boolean;
  is_upcoming: boolean;
  has_record: boolean;
  registrations_count: number;
  published_at: string | null;
  cover?: Media | null;
}

export interface AcademySession extends AcademyCard {
  description: string | null;
  takeaways: string[];
  moderation_note?: string | null;
  author?: AuthorRef;
  /** Ссылки приходят только записавшимся — иначе поля в ответе просто нет. */
  stream_url?: string;
  record_url?: string;
  viewer: {
    registered: boolean;
    can_watch: boolean;
    registered_at: string | null;
  };
}

export interface AcademyRegistrationRow {
  id: number;
  created_at: string | null;
  reminded_at: string | null;
  watched_at: string | null;
  user: { id: number | null; name: string | null; email: string | null; verified: boolean };
}

/* ---------- рейтинг ---------- */

export type RatingLevel = 'novice' | 'cook' | 'chef' | 'guru';
export type RatingSource = 'visit' | 'streak' | 'recipe' | 'comment' | 'photo_report';

export interface RatingLevelInfo {
  value: RatingLevel;
  label: string;
  threshold: number;
}

export interface RatingBreakdownRow {
  source: RatingSource;
  label: string;
  unit: string;
  count: number;
  points: number;
}

export interface MyRating {
  points: number;
  level: RatingLevel;
  level_label: string;
  next_level: RatingLevel | null;
  next_level_label: string | null;
  points_to_next: number | null;
  /** Доля пути до следующего уровня, от 0 до 1. */
  progress: number;
  streak_days: number;
  streak_best: number;
  visits_total: number;
  position: number | null;
  breakdown: RatingBreakdownRow[];
}

export interface LeaderboardRow {
  id: number;
  name: string;
  rating_points: number;
  rating_level: RatingLevel;
  rating_label: string;
  streak_days: number;
  recipes_count?: number;
  reviews_count?: number;
  position?: number;
  is_me: boolean;
}

export interface Leaderboard {
  data: LeaderboardRow[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    levels: RatingLevelInfo[];
    /** За что начисляются баллы и сколько. */
    sources?: Array<{ value: string; label: string; points: number }>;
  };
}

/* ---------- журнал ---------- */

export type JournalPeriod = 'week' | 'month';

export interface JournalIssueCard {
  id: number;
  slug: string;
  title: string;
  lead: string | null;
  period: JournalPeriod;
  /** «Гайд недели» или «Гайд месяца». */
  period_label: string;
  /** Срок выпуска словами: «13–19 января 2026» или «Январь 2026». */
  period_range: string;
  issue_date: string | null;
  issue_year: number;
  issue_month: number;
  status: PublishStatus;
  pages: number | null;
  file_size: number | null;
  has_file: boolean;
  downloads_count: number;
  published_at: string | null;
  cover?: Media | null;
}

export interface JournalIssue extends JournalIssueCard {
  description: string | null;
  moderation_note?: string | null;
  /** Исходное имя загруженного PDF. Приходит только сотрудникам редакции. */
  file_name?: string | null;
  author?: AuthorRef;
}

/** Месяц, в котором есть выпуски. */
export interface JournalMonth {
  month: number;
  label: string;
  count: number;
}

export interface JournalYear {
  year: number;
  count: number;
  months: JournalMonth[];
}

export interface JournalPeriods {
  data: JournalYear[];
  meta: { total: number };
}

/** Конверт постраничного ответа Laravel. */
export interface Paginated<T> {
  data: T[];
  links?: { first?: string; last?: string; prev?: string | null; next?: string | null };
  meta: {
    current_page: number;
    from?: number | null;
    last_page: number;
    per_page?: number;
    to?: number | null;
    total: number;
  };
}

export interface Envelope<T> {
  data: T;
  meta?: Record<string, unknown>;
}

/** Ошибка валидации Laravel. */
export interface ApiError {
  message: string;
  code?: string;
  errors?: Record<string, string[]>;
}

export const DIFFICULTY_LABELS: Record<Difficulty, string> = {
  easy: 'Просто',
  medium: 'Средне',
  hard: 'Сложно',
};

export const STATUS_LABELS: Record<RecipeStatus, string> = {
  draft: 'Черновик',
  moderation: 'На модерации',
  published: 'Опубликован',
  rejected: 'Возвращён',
  archived: 'Архив',
};

export const ROLE_LABELS: Record<UserRole, string> = {
  user: 'Пользователь',
  content_manager: 'Контент-менеджер',
  editor_lead: 'Руководитель редакции',
  admin: 'Администратор',
};

export const PUBLISH_STATUS_LABELS: Record<PublishStatus, string> = {
  draft: 'Черновик',
  moderation: 'На проверке',
  published: 'Опубликован',
  archived: 'Архив',
};

export const CONTEST_KIND_LABELS: Record<ContestKind, string> = {
  photo: 'Фото',
  video: 'Видео',
  recipe: 'Рецепт',
};

export const ENTRY_STATUS_LABELS: Record<EntryStatus, string> = {
  pending: 'На модерации',
  approved: 'Принята',
  rejected: 'Отклонена',
};

export const ACADEMY_FORMAT_LABELS: Record<AcademyFormat, string> = {
  webinar: 'Вебинар',
  stream: 'Прямой эфир',
  lesson: 'Видеоурок',
};

export const ACADEMY_LEVEL_LABELS: Record<AcademyLevel, string> = {
  basic: 'Начальный',
  medium: 'Средний',
  advanced: 'Продвинутый',
};

export const RATING_LEVEL_LABELS: Record<RatingLevel, string> = {
  novice: 'Новичок',
  cook: 'Повар',
  chef: 'Шеф',
  guru: 'Гуру',
};

export const JOURNAL_PERIOD_LABELS: Record<JournalPeriod, string> = {
  week: 'Гайд недели',
  month: 'Гайд месяца',
};
