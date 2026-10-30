<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $book_id
 * @property int $position
 * @property string $title
 * @property string $body
 * @property bool $is_preview
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Book $book
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookChapter newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookChapter newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookChapter query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookChapter whereBody($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookChapter whereBookId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookChapter whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookChapter whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookChapter whereIsPreview($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookChapter wherePosition($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookChapter whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookChapter whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class BookChapter extends Model
{
    protected $fillable = ['position', 'title', 'body', 'is_preview'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'is_preview' => 'boolean'];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
