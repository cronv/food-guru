<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'activity_log')]
class ActivityLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'actor_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    #[ORM\Column(length: 100)]
    private string $action;

    #[ORM\Column(name: 'subject_type', length: 100, nullable: true)]
    private ?string $subjectType = null;

    #[ORM\Column(name: 'subject_id', type: Types::BIGINT, nullable: true, options: ['unsigned' => true])]
    private ?int $subjectId = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $payload = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $payload */
    public function __construct(?User $actor, string $action, ?User $subject = null, array $payload = [])
    {
        $this->actor = $actor;
        $this->action = $action;
        if ($subject) {
            $this->subjectType = 'User';
            $this->subjectId = $subject->getId();
        }
        $this->payload = $payload ?: null;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
