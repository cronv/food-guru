<?php

namespace App\Models;

use App\Enums\JournalPeriod;
use App\Enums\PublishStatus;
use App\Support\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $author_id
 * @property int|null $cover_media_id
 * @property string $slug
 * @property string $title
 * @property string|null $lead
 * @property string|null $description
 * @property JournalPeriod $period
 * @property Carbon $issue_date
 * @property int $issue_year
 * @property int $issue_month
 * @property string|null $file_path
 * @property string $file_disk
 * @property string|null $file_name
 * @property int|null $file_size
 * @property int|null $pages
 * @property PublishStatus $status
 * @property Carbon|null $published_at
 * @property int $downloads_count
 * @property string|null $moderation_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $author
 * @property-read Media|null $cover
 * @property-read int|null $total  selectRaw count(*) в JournalController::periods
 *
 * @method static Builder<static>|JournalIssue newModelQuery()
 * @method static Builder<static>|JournalIssue newQuery()
 * @method static Builder<static>|JournalIssue published()
 * @method static Builder<static>|JournalIssue query()
 * @method static Builder<static>|JournalIssue search(?string $term)
 * @method static Builder<static>|JournalIssue whereAuthorId($value)
 * @method static Builder<static>|JournalIssue whereCoverMediaId($value)
 * @method static Builder<static>|JournalIssue whereCreatedAt($value)
 * @method static Builder<static>|JournalIssue whereDescription($value)
 * @method static Builder<static>|JournalIssue whereDownloadsCount($value)
 * @method static Builder<static>|JournalIssue whereFileDisk($value)
 * @method static Builder<static>|JournalIssue whereFileName($value)
 * @method static Builder<static>|JournalIssue whereFilePath($value)
 * @method static Builder<static>|JournalIssue whereFileSize($value)
 * @method static Builder<static>|JournalIssue whereId($value)
 * @method static Builder<static>|JournalIssue whereIssueDate($value)
 * @method static Builder<static>|JournalIssue whereIssueMonth($value)
 * @method static Builder<static>|JournalIssue whereIssueYear($value)
 * @method static Builder<static>|JournalIssue whereLead($value)
 * @method static Builder<static>|JournalIssue whereModerationNote($value)
 * @method static Builder<static>|JournalIssue wherePages($value)
 * @method static Builder<static>|JournalIssue wherePeriod($value)
 * @method static Builder<static>|JournalIssue wherePublishedAt($value)
 * @method static Builder<static>|JournalIssue whereSlug($value)
 * @method static Builder<static>|JournalIssue whereStatus($value)
 * @method static Builder<static>|JournalIssue whereTitle($value)
 * @method static Builder<static>|JournalIssue whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class JournalIssue extends Model
{
    use Searchable;

    protected $fillable = [
        'author_id',
        'cover_media_id',
        'slug',
        'title',
        'lead',
        'description',
        'period',
        'issue_date',
        'issue_year',
        'issue_month',
        'file_path',
        'file_disk',
        'file_name',
        'file_size',
        'pages',
        'status',
        'published_at',
        'moderation_note',
    ];

    protected function casts(): array
    {
        return [
            'period' => JournalPeriod::class,
            'status' => PublishStatus::class,
            'issue_date' => 'date',
            'published_at' => 'datetime',
            'issue_year' => 'integer',
            'issue_month' => 'integer',
            'file_size' => 'integer',
            'pages' => 'integer',
            'downloads_count' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', PublishStatus::Published)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function isPublic(): bool
    {
        return $this->status === PublishStatus::Published
            && $this->published_at !== null
            && $this->published_at->isPast();
    }

    public function hasFile(): bool
    {
        return filled($this->file_path);
    }

    /** Подпись периода: «13–19 января 2026» или «Январь 2026». */
    public function periodLabel(): string
    {
        // issue_date приводится к дате в casts() — это всегда Carbon.
        return $this->period->describe($this->issue_date);
    }

    /**
     * Имя, под которым файл уедет в «Загрузки».
     * Берём заголовок, а не внутреннее имя файла: в папке у человека
     * должно лежать «Гайд недели 13-19 января.pdf», а не «8f3c-...pdf».
     */
    public function downloadName(): string
    {
        $base = trim(preg_replace('/\s+/u', ' ', $this->title));
        $base = preg_replace('/[\\\\\/:*?"<>|]+/u', '', $base);
        $base = mb_substr($base, 0, 120);

        return ($base !== '' ? $base : 'guide').'.pdf';
    }

    /** Приводит год и месяц к дате выпуска — одна точка правды. */
    public function syncPeriodColumns(): void
    {
        $date = $this->period->normalize($this->issue_date);

        $this->issue_date = $date;
        $this->issue_year = (int) $date->year;
        $this->issue_month = (int) $date->month;
    }
}
