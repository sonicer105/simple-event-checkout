<?php

declare(strict_types=1);

namespace App\Auth;

use App\Repositories\AdminRepository;
use App\Repositories\AdminSessionRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\LoginLockoutRepository;
use DateInterval;
use DateTimeImmutable;

final class AdminAuth
{
    private const COOKIE_NAME = 'sec_admin';
    private const SESSION_TTL = 86400;
    private const LOCKOUT_WINDOW_SECONDS = 300;
    private const LOCKOUT_MAX_ATTEMPTS = 5;
    private const LOCKOUT_BAN_SECONDS = 3600;

    public function __construct(
        private AdminRepository $admins,
        private AdminSessionRepository $sessions,
        private LoginLockoutRepository $lockouts,
        private AuditLogRepository $audit,
        private array $config
    ) {
    }

    public function isLockedOut(string $ip): bool
    {
        $lockout = $this->lockouts->findByIp($ip);
        if (!$lockout || !$lockout['banned_until']) {
            return false;
        }

        return (new DateTimeImmutable($lockout['banned_until'])) > new DateTimeImmutable();
    }

    public function authenticate(string $username, string $password, string $ip): ?array
    {
        if ($this->isLockedOut($ip)) {
            $this->audit->log(null, 'admin_login_lockout', 'Login blocked by lockout', $ip);
            return null;
        }

        $admin = $this->admins->findByUsername($username);
        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            $this->lockouts->registerAttempt($ip, self::LOCKOUT_WINDOW_SECONDS, self::LOCKOUT_MAX_ATTEMPTS, self::LOCKOUT_BAN_SECONDS);
            $this->audit->log($admin['id'] ?? null, 'admin_login_failure', 'Invalid credentials', $ip);
            return null;
        }

        $this->lockouts->clear($ip);
        $this->audit->log((int) $admin['id'], 'admin_login_success', 'Password accepted', $ip);

        return $admin;
    }

    public function requiresTwoFactor(array $admin): bool
    {
        return (bool) $admin['app_2fa_enabled'] && !empty($admin['totp_secret']);
    }

    public function createSession(int $adminId): string
    {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = (new DateTimeImmutable())->add(new DateInterval('PT' . self::SESSION_TTL . 'S'));

        $this->sessions->create($adminId, $tokenHash, $expiresAt->format('Y-m-d H:i:s'));
        $this->admins->markLogin($adminId);

        return $token;
    }

    public function revokeSession(string $token): void
    {
        $tokenHash = hash('sha256', $token);
        $this->sessions->deleteByToken($tokenHash);
    }

    public function getSessionCookieParams(): array
    {
        $forceHttps = (bool) (($this->config['security']['force_https'] ?? false) ?: false);
        $secure = $forceHttps || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

        return [
            'expires' => time() + self::SESSION_TTL,
            'path' => '/admin',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    public function getCurrentAdmin(): ?array
    {
        $token = $_COOKIE[self::COOKIE_NAME] ?? '';
        if ($token === '') {
            return null;
        }

        $tokenHash = hash('sha256', $token);
        $session = $this->sessions->findByToken($tokenHash);
        if (!$session) {
            return null;
        }

        if (new DateTimeImmutable($session['expires_at']) <= new DateTimeImmutable()) {
            $this->sessions->deleteByToken($tokenHash);
            return null;
        }

        return $this->admins->findById((int) $session['admin_id']);
    }

    public function cookieName(): string
    {
        return self::COOKIE_NAME;
    }
}
