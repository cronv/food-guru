<?php

namespace App\Models;

use App\Enums\RatingLevel;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $password
 * @property string|null $pending_email
 * @property UserRole $role
 * @property Carbon|null $email_verified_at
 * @property bool $is_active
 * @property int $rating_points
 * @property RatingLevel $rating_level
 * @property int $streak_days
 * @property int $streak_best
 * @property Carbon|null $last_visit_on
 * @property int $visits_total
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, AcademyRegistration> $academyRegistrations
 * @property-read int|null $academy_registrations_count
 * @property-read Collection<int, ActivityLog> $activity
 * @property-read int|null $activity_count
 * @property-read Collection<int, ContestEntry> $contestEntries
 * @property-read int|null $contest_entries_count
 * @property-read Collection<int, ContestVote> $contestVotes
 * @property-read int|null $contest_votes_count
 * @property-read Collection<int, CookbookNote> $cookbookNotes
 * @property-read int|null $cookbook_notes_count
 * @property-read Collection<int, Recipe> $cookbookRecipes
 * @property-read int|null $cookbook_recipes_count
 * @property-read Collection<int, EmailToken> $emailTokens
 * @property-read int|null $email_tokens_count
 * @property-read Collection<int, Recipe> $favorites
 * @property-read int|null $favorites_count
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read Collection<int, RatingEvent> $ratingEvents
 * @property-read int|null $rating_events_count
 * @property-read Collection<int, Recipe> $recipes
 * @property-read int|null $recipes_count
 * @property-read Collection<int, Review> $reviews
 * @property-read int|null $reviews_count
 * @property-read Collection<int, PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 * @property int|null $position место в таблице лидеров, вычисляется в RatingController
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereLastVisitOn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePendingEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRatingLevel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRatingPoints($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereStreakBest($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereStreakDays($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereVisitsTotal($value)
 *
 * @mixin \Eloquent
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'pending_email',
        'role',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            // password не в $fillable: задаётся только через setPassword(),
            // чтобы его нельзя было подсунуть массовым присвоением из запроса.
            'password' => 'hashed',
            'role' => UserRole::class,
            'rating_level' => RatingLevel::class,
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'last_visit_on' => 'date',
            'rating_points' => 'integer',
            'streak_days' => 'integer',
            'streak_best' => 'integer',
            'visits_total' => 'integer',
        ];
    }

    /**
     * Выйти на всех устройствах: удалить сессии (кроме $keep — текущей)
     * и обнулить cookie «запомнить меня».
     */
    public function endSessions(?string $keep = null): void
    {
        DB::table('sessions')
            ->where('user_id', $this->id)
            ->when($keep, fn ($q) => $q->where('id', '!=', $keep))
            ->delete();

        $this->setRememberToken(Str::random(60));
        $this->save();
    }

    /** @return HasMany<Recipe, $this> */
    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class, 'author_id');
    }

    public function emailTokens(): HasMany
    {
        return $this->hasMany(EmailToken::class);
    }

    /** @return BelongsToMany<Recipe, $this> */
    public function favorites(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class, 'favorites')
            ->withPivot('created_at')
            ->orderByPivot('created_at', 'desc');
    }

    /**
     * Рецепты сайта в рецептосохранялке.
     *
     * @return BelongsToMany<Recipe, $this>
     */
    public function cookbookRecipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class, 'cookbook_recipes')
            ->withPivot('created_at')
            ->orderByPivot('created_at', 'desc');
    }

    /** @return HasMany<CookbookNote, $this> */
    public function cookbookNotes(): HasMany
    {
        return $this->hasMany(CookbookNote::class);
    }

    public function activity(): HasMany
    {
        return $this->hasMany(ActivityLog::class, 'actor_id');
    }

    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function contestEntries(): HasMany
    {
        return $this->hasMany(ContestEntry::class);
    }

    public function contestVotes(): HasMany
    {
        return $this->hasMany(ContestVote::class);
    }

    public function academyRegistrations(): HasMany
    {
        return $this->hasMany(AcademyRegistration::class);
    }

    public function ratingEvents(): HasMany
    {
        return $this->hasMany(RatingEvent::class);
    }

    /**
     * Сколько баллов осталось до следующего уровня.
     * null — уровень последний, расти больше некуда.
     */
    public function pointsToNextLevel(): ?int
    {
        $next = $this->rating_level->next();

        return $next ? max(0, $next->threshold() - $this->rating_points) : null;
    }

    /** Доля пройденного пути до следующего уровня, от 0 до 1. */
    public function levelProgress(): float
    {
        $next = $this->rating_level->next();

        if (! $next) {
            return 1.0;
        }

        $from = $this->rating_level->threshold();
        $span = max(1, $next->threshold() - $from);

        return min(1.0, max(0.0, ($this->rating_points - $from) / $span));
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function hasPassword(): bool
    {
        return $this->password !== null;
    }

    public function setPassword(string $plain): void
    {
        $this->forceFill(['password' => $plain])->save();
    }

    /** Есть ли доступ в админку хотя бы на уровне контент-менеджера. */
    public function isStaff(): bool
    {
        return $this->role->atLeast(UserRole::ContentManager);
    }

    public function atLeast(UserRole $role): bool
    {
        return $this->role->atLeast($role);
    }
}
