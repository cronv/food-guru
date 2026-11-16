<?php

declare(strict_types=1);

namespace App\Security;

use App\Content\Publishable;
use App\Entity\User;
use App\Enum\UserRole;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Кто видит материал (метод view() для Journal, Contest,
 * Academy, Recipe): опубликованное — все; черновик — автор и руководитель
 * редакции и выше.
 *
 * @extends Voter<string, Publishable>
 */
final class PublishableVoter extends Voter
{
    public const VIEW = 'PUBLISHABLE_VIEW';

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::VIEW === $attribute && $subject instanceof Publishable;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if ($subject->isPublic($this->clock->now())) {
            return true;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return $subject->authorId === $user->getId() || $user->getRole()->atLeast(UserRole::EditorLead);
    }
}
