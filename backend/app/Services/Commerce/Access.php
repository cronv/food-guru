<?php

namespace App\Services\Commerce;

use App\Models\Book;
use App\Models\BookChapter;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Кто что может открыть. Все проверки доступа к платному — здесь, чтобы
 * правило «подписчик читает книги из подписки» не разъехалось по контроллерам.
 */
class Access
{
    /**
     * До какого момента оплачен доступ, или null, если подписки нет сейчас.
     * Продления идут встык, поэтому достаточно самого позднего конца —
     * но только если какой-то период действует прямо сейчас.
     */
    public function subscriptionEndsAt(?User $user): ?CarbonInterface
    {
        if (! $this->hasSubscription($user)) {
            return null;
        }

        $last = Subscription::where('user_id', $user->id)->max('ends_at');

        return $last ? Carbon::parse($last) : null;
    }

    public function hasSubscription(?User $user): bool
    {
        return $user !== null && Subscription::where('user_id', $user->id)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->exists();
    }

    public function owns(?User $user, Book $book): bool
    {
        return $user !== null && $book->purchases()->where('user_id', $user->id)->exists();
    }

    /** Читать всю книгу на сайте. */
    public function canRead(?User $user, Book $book): bool
    {
        return $book->isFree()
            || $this->owns($user, $book)
            || ($book->in_subscription && $this->hasSubscription($user));
    }

    public function canReadChapter(?User $user, Book $book, BookChapter $chapter): bool
    {
        return $chapter->is_preview || $this->canRead($user, $book);
    }

    /**
     * Скачать PDF. Только бесплатные и купленные: книга по подписке после
     * скачивания осталась бы у человека и после конца подписки.
     */
    public function canDownload(?User $user, Book $book): bool
    {
        return $user !== null && ($book->isFree() || $this->owns($user, $book));
    }
}
