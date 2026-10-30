<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $academy_session_id
 * @property int $user_id
 * @property Carbon|null $reminded_at
 * @property Carbon|null $watched_at
 * @property Carbon $created_at
 * @property-read AcademySession $session
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademyRegistration newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademyRegistration newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademyRegistration query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademyRegistration whereAcademySessionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademyRegistration whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademyRegistration whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademyRegistration whereRemindedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademyRegistration whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademyRegistration whereWatchedAt($value)
 *
 * @mixin \Eloquent
 */
class AcademyRegistration extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'academy_session_id',
        'user_id',
        'reminded_at',
        'watched_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'reminded_at' => 'datetime',
            'watched_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademySession::class, 'academy_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
