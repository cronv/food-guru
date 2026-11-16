<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Api\Validation\InputValidator;
use App\Entity\ActivityLog;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email as MimeEmail;
use Symfony\Component\Routing\Attribute\Route;

final class AuthController
{
    public function __construct(
        private readonly Connection $db,
        private readonly EntityManagerInterface $em,
        private readonly InputValidator $validator,
        private readonly Security $security,
        private readonly MailerInterface $mailer,
        private readonly ClockInterface $clock,
        private readonly \Symfony\Component\RateLimiter\RateLimiterFactory $authMailLimiter,
        private readonly \Symfony\Component\RateLimiter\RateLimiterFactory $authMailIpLimiter,
    ) {
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }

    private function checkRateLimit(Request $request, string $email): ?JsonResponse
    {
        $ip = (string) $request->getClientIp();
        $perEmail = $this->authMailLimiter->create($email.'|'.$ip);
        if (!$perEmail->consume(1)->isAccepted()) {
            return new JsonResponse(['message' => 'Слишком много запросов. Попробуйте позже.'], 429);
        }
        $perIp = $this->authMailIpLimiter->create($ip);
        if (!$perIp->consume(1)->isAccepted()) {
            return new JsonResponse(['message' => 'Слишком много запросов. Попробуйте позже.'], 429);
        }

        return null;
    }

    private function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    private function issueToken(int $userId, string $type, ?string $email, \DateInterval $ttl): string
    {
        // Гасим предыдущие неиспользованные токены того же типа.
        $this->db->executeStatement(
            'UPDATE email_tokens SET used_at = ? WHERE user_id = ? AND type = ? AND used_at IS NULL',
            [$this->now()->format('Y-m-d H:i:s'), $userId, $type]
        );

        $plain = bin2hex(random_bytes(32));
        $expires = $this->now()->add($ttl);

        $this->db->insert('email_tokens', [
            'user_id' => $userId,
            'token_hash' => $this->hashToken($plain),
            'type' => $type,
            'email' => $email,
            'expires_at' => $expires->format('Y-m-d H:i:s'),
            'created_at' => $this->now()->format('Y-m-d H:i:s'),
        ]);

        return $plain;
    }

    /** @return array<string, mixed>|null */
    private function findToken(string $plain, string $type): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM email_tokens WHERE token_hash = ? AND type = ? AND used_at IS NULL AND expires_at > ?',
            [$this->hashToken($plain), $type, $this->now()->format('Y-m-d H:i:s')]
        );

        return $row ?: null;
    }

    private function consumeToken(int $id): bool
    {
        $affected = $this->db->executeStatement(
            'UPDATE email_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL',
            [$this->now()->format('Y-m-d H:i:s'), $id]
        );

        return 1 === $affected;
    }

    /** @return array<string, mixed>|null */
    private function userRow(int $id): ?array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM users WHERE id = ?', [$id]);

        return $row ?: null;
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed> */
    private function userResource(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'role' => $row['role'],
            'email_verified_at' => $row['email_verified_at'],
            'is_active' => (bool) $row['is_active'],
        ];
    }

    #[Route('/auth/register', name: 'auth_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $data = $this->validator->validate($request, [
            'name' => 'required|string|min:2|max:80',
            'email' => 'required|string|email|max:255',
        ]);

        $email = mb_strtolower(trim((string) $data['email']));

        $rate = $this->checkRateLimit($request, $email);
        if ($rate) {
            return $rate;
        }

        $exists = (int) $this->db->fetchOne('SELECT COUNT(*) FROM users WHERE email = ?', [$email]);
        if ($exists > 0) {
            return new JsonResponse(['data' => ['status' => 'sent']], 202);
        }

        $this->db->insert('users', [
            'name' => trim((string) $data['name']),
            'email' => $email,
            'role' => 'user',
            'is_active' => 1,
            'created_at' => $this->now()->format('Y-m-d H:i:s'),
            'updated_at' => $this->now()->format('Y-m-d H:i:s'),
        ]);

        $userId = (int) $this->db->lastInsertId();

        // Отправка письма подтверждения.
        $token = $this->issueToken($userId, 'verify', $email, new \DateInterval('PT24H'));
        $siteUrl = rtrim((string) ($_ENV['SITE_URL'] ?? 'http://localhost:4200'), '/');
        $verifyUrl = $siteUrl.'/verify/'.$token;

        $msg = (new MimeEmail())
            ->to($email)
            ->subject('Подтверждение регистрации')
            ->text('Перейдите по ссылке: '.$verifyUrl);
        $this->mailer->send($msg);

        return new JsonResponse(['data' => ['status' => 'sent']], 202);
    }

    #[Route('/auth/login', name: 'auth_login_request', methods: ['POST'])]
    public function requestLoginLink(Request $request): JsonResponse
    {
        $data = $this->validator->validate($request, [
            'email' => 'required|string|email|max:255',
        ]);

        $email = mb_strtolower(trim((string) $data['email']));

        $rate = $this->checkRateLimit($request, $email);
        if ($rate) {
            return $rate;
        }

        $user = $this->db->fetchAssociative('SELECT * FROM users WHERE email = ?', [$email]);

        if ($user && (bool) $user['is_active'] && null !== $user['email_verified_at']) {
            // cooldown check
            $last = $this->db->fetchAssociative(
                'SELECT created_at FROM email_tokens WHERE user_id = ? AND type = ? ORDER BY created_at DESC LIMIT 1',
                [(int) $user['id'], 'login']
            );
            $canSend = true;
            if ($last) {
                $next = (new \DateTimeImmutable($last['created_at']))->add(new \DateInterval('PT60S'));
                if ($next > $this->now()) {
                    $canSend = false;
                }
            }
            if ($canSend) {
                $token = $this->issueToken((int) $user['id'], 'login', $email, new \DateInterval('PT15M'));
                $siteUrl = rtrim((string) ($_ENV['SITE_URL'] ?? 'http://localhost:4200'), '/');
                $loginUrl = $siteUrl.'/login/'.$token;
                $msg = (new MimeEmail())
                    ->to($email)
                    ->subject('Вход на сайт')
                    ->text('Перейдите по ссылке: '.$loginUrl);
                $this->mailer->send($msg);
            }
        }

        return new JsonResponse([
            'data' => [
                'status' => 'sent',
                'email' => $email,
                'cooldown_seconds' => 60,
            ],
        ], 202);
    }

    #[Route('/auth/login/confirm', name: 'auth_login_confirm', methods: ['POST'])]
    public function confirmLoginLink(Request $request): JsonResponse
    {
        $data = $this->validator->validate($request, [
            'token' => 'required|string',
        ]);

        $tokenRow = $this->findToken((string) $data['token'], 'login');
        if (!$tokenRow) {
            return new JsonResponse(['message' => 'Ссылка устарела или уже использована.', 'code' => 'token_expired'], 410);
        }

        $user = $this->userRow((int) $tokenRow['user_id']);
        if (!$user) {
            return new JsonResponse(['message' => 'Ссылка устарела или уже использована.', 'code' => 'token_expired'], 410);
        }

        if (!(bool) $user['is_active']) {
            return new JsonResponse(['message' => 'Аккаунт отключён.', 'code' => 'account_disabled'], 403);
        }

        if (!$this->consumeToken((int) $tokenRow['id'])) {
            return new JsonResponse(['message' => 'Ссылка устарела или уже использована.', 'code' => 'token_expired'], 410);
        }

        // Symfony Security login.
        $userEntity = $this->em->find(User::class, (int) $user['id']);
        if (!$userEntity instanceof User) {
            throw new \RuntimeException('User not found.');
        }
        $this->security->login($userEntity);

        $this->em->persist(new ActivityLog($userEntity, 'auth.login', $userEntity));
        $this->em->flush();

        return new JsonResponse(['data' => $this->userResource($user)]);
    }

    #[Route('/auth/login/password', name: 'auth_login_password', methods: ['POST'])]
    public function passwordLogin(Request $request): JsonResponse
    {
        $data = $this->validator->validate($request, [
            'email' => 'required|string|email|max:255',
            'password' => 'required|string',
        ]);

        $email = mb_strtolower(trim((string) $data['email']));
        $user = $this->db->fetchAssociative('SELECT * FROM users WHERE email = ?', [$email]);

        if (!$user || !(bool) $user['is_active'] || empty($user['password']) || !password_verify((string) $data['password'], (string) $user['password'])) {
            return new JsonResponse(['message' => 'Неверный email или пароль.', 'code' => 'invalid_credentials'], 422);
        }

        $userEntity = $this->em->find(User::class, (int) $user['id']);
        if (!$userEntity instanceof User) {
            throw new \RuntimeException('User not found.');
        }
        $this->security->login($userEntity);

        $this->em->persist(new ActivityLog($userEntity, 'auth.login', $userEntity));
        $this->em->flush();

        return new JsonResponse(['data' => $this->userResource($user)]);
    }

    #[Route('/auth/verify', name: 'auth_verify', methods: ['POST'])]
    public function verify(Request $request): JsonResponse
    {
        $data = $this->validator->validate($request, [
            'token' => 'required|string',
        ]);

        $tokenRow = $this->findToken((string) $data['token'], 'verify');
        if (!$tokenRow) {
            return new JsonResponse(['message' => 'Ссылка устарела или уже использована.', 'code' => 'token_expired'], 410);
        }

        if (!$this->consumeToken((int) $tokenRow['id'])) {
            return new JsonResponse(['message' => 'Ссылка устарела или уже использована.', 'code' => 'token_expired'], 410);
        }

        $user = $this->userRow((int) $tokenRow['user_id']);
        if (!$user) {
            return new JsonResponse(['message' => 'Ссылка устарела или уже использована.', 'code' => 'token_expired'], 410);
        }

        $email = $tokenRow['email'] ?: $user['email'];
        $this->db->update('users', [
            'email' => $email,
            'email_verified_at' => $this->now()->format('Y-m-d H:i:s'),
            'pending_email' => null,
            'updated_at' => $this->now()->format('Y-m-d H:i:s'),
        ], ['id' => (int) $user['id']]);

        $user = $this->userRow((int) $user['id']);
        if (!$user) {
            return new JsonResponse(['message' => 'Ссылка устарела или уже использована.', 'code' => 'token_expired'], 410);
        }
        $userEntity = $this->em->find(User::class, (int) $user['id']);
        if (!$userEntity instanceof User) {
            throw new \RuntimeException('User not found.');
        }
        $this->security->login($userEntity);

        $this->em->persist(new ActivityLog($userEntity, 'email.verified', $userEntity));
        $this->em->flush();

        return new JsonResponse(['data' => $this->userResource($user)]);
    }

    #[Route('/auth/resend', name: 'auth_resend', methods: ['POST'])]
    public function resend(Request $request): JsonResponse
    {
        $data = $this->validator->validate($request, [
            'email' => 'required|string|email|max:255',
        ]);

        $email = mb_strtolower(trim((string) $data['email']));

        $rate = $this->checkRateLimit($request, $email);
        if ($rate) {
            return $rate;
        }

        $user = $this->db->fetchAssociative('SELECT * FROM users WHERE email = ?', [$email]);

        if ($user && (bool) $user['is_active']) {
            $token = $this->issueToken((int) $user['id'], 'verify', $email, new \DateInterval('PT24H'));
            $siteUrl = rtrim((string) ($_ENV['SITE_URL'] ?? 'http://localhost:4200'), '/');
            $verifyUrl = $siteUrl.'/verify/'.$token;
            $msg = (new MimeEmail())
                ->to($email)
                ->subject('Подтверждение регистрации')
                ->text('Перейдите по ссылке: '.$verifyUrl);
            $this->mailer->send($msg);
        }

        return new JsonResponse(['data' => ['status' => 'sent']], 202);
    }

    #[Route('/auth/logout', name: 'auth_logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $this->security->logout();
        $request->getSession()->invalidate();

        return new JsonResponse(['data' => ['status' => 'logged_out']]);
    }

    #[Route('/me/password', name: 'me_password', methods: ['PUT'])]
    public function changePassword(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }

        $row = $this->userRow((int) $user->getId());
        if (!$row) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }
        $hasPassword = !empty($row['password']);

        $rules = ['password' => 'required|string|min:8|max:255'];
        if ($hasPassword) {
            $rules['current_password'] = 'required|string';
        }
        $data = $this->validator->validate($request, $rules);

        $password = (string) $data['password'];
        if (mb_strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            return new JsonResponse(['message' => 'Пароль должен быть не короче 8 символов и содержать буквы и цифры.', 'code' => 'invalid_password'], 422);
        }

        if ($hasPassword && !password_verify((string) ($data['current_password'] ?? ''), (string) $row['password'])) {
            return new JsonResponse(['message' => 'Неверный текущий пароль.', 'code' => 'invalid_password'], 422);
        }

        $wasSet = !empty($row['password']);

        $this->db->update('users', [
            'password' => password_hash((string) $data['password'], \PASSWORD_BCRYPT),
            'updated_at' => $this->now()->format('Y-m-d H:i:s'),
        ], ['id' => (int) $user->getId()]);

        // Закрываем остальные сессии.
        $currentSessionId = $request->hasSession() ? $request->getSession()->getId() : null;
        if (null !== $currentSessionId) {
            $this->db->executeStatement(
                'DELETE FROM sessions WHERE user_id = ? AND id != ?',
                [(int) $user->getId(), $currentSessionId]
            );
        }

        $userEntity = $this->em->find(User::class, (int) $user->getId());
        if (!$userEntity instanceof User) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }
        $this->em->persist(new ActivityLog($userEntity, $wasSet ? 'password.changed' : 'password.created', $userEntity));
        $this->em->flush();

        return new JsonResponse(['data' => ['status' => 'updated']]);
    }
}
