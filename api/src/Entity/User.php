<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UserRole;
use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Таблица users, созданная прежними миграциями. Отображены колонки, которые уже
 * нужны Symfony; остальные добавляются вместе с модулями, которым они нужны.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255, unique: true)]
    private string $email;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $password = null;

    #[ORM\Column(name: 'pending_email', length: 255, nullable: true)]
    private ?string $pendingEmail = null;

    #[ORM\Column(length: 255, enumType: UserRole::class, options: ['default' => 'user'])]
    private UserRole $role = UserRole::User;

    #[ORM\Column(name: 'email_verified_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $active = true;

    /** День последнего захода (UTC). Пишет RatingService, здесь — только для дешёвой проверки. */
    #[ORM\Column(name: 'last_visit_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastVisitOn = null;

    public function __construct(string $name, string $email)
    {
        $this->name = $name;
        $this->email = $email;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getRole(): UserRole
    {
        return $this->role;
    }

    public function setRole(UserRole $role): void
    {
        $this->role = $role;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    /** Уже отмечался сегодня — тогда в базу за заходом не ходим. */
    public function hasVisitedOn(\DateTimeImmutable $now): bool
    {
        return null !== $this->lastVisitOn
            && $this->lastVisitOn->format('Y-m-d') === $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
    }

    /** Сотрудник редакции: контент-менеджер и выше. */
    public function isStaff(): bool
    {
        return $this->role->atLeast(UserRole::ContentManager);
    }

    public function isVerified(): bool
    {
        return null !== $this->emailVerifiedAt;
    }

    public function markVerified(\DateTimeImmutable $at): void
    {
        $this->emailVerifiedAt = $at;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): void
    {
        $this->password = $password;
    }

    public function getPendingEmail(): ?string
    {
        return $this->pendingEmail;
    }

    public function setPendingEmail(?string $email): void
    {
        $this->pendingEmail = $email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /** Провайдер ищет пользователя по id: его хранит сессия. */
    public function getUserIdentifier(): string
    {
        return (string) ($this->id ?? throw new \LogicException('Пользователь ещё не сохранён.'));
    }

    public function getRoles(): array
    {
        return [$this->role->securityRole()];
    }

    public function eraseCredentials(): void
    {
    }
}
