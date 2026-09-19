<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Вход в панель: пароль + сессия в БД.
 * В cookie лежит случайный токен, в таблице — только его sha256.
 */
final class Auth
{
    public const COOKIE = 'reposter_session';
    private const SESSION_DAYS = 14;
    private const MAX_ATTEMPTS = 5;
    private const LOCK_MINUTES = 15;

    private ?array $admin = null;

    public function __construct(private readonly Request $request)
    {
    }

    public function sessionToken(): string
    {
        return $this->request->cookie(self::COOKIE);
    }

    public function user(): ?array
    {
        if ($this->admin !== null) {
            return $this->admin;
        }
        $token = $this->sessionToken();
        if ($token === '') {
            return null;
        }
        $row = Db::one(
            'SELECT a.* FROM admin_sessions s JOIN admins a ON a.id = s.admin_id
             WHERE s.id = ? AND s.expires_at > ? AND a.is_active = 1',
            [hash('sha256', $token), Db::now()]
        );
        if ($row === null) {
            return null;
        }
        Db::update('admin_sessions', ['last_seen_at' => Db::now()], 'id = :id', ['id' => hash('sha256', $token)]);
        return $this->admin = $row;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    /** @return array{0:bool, 1:string, 2:?string} успех, сообщение, новый токен сессии */
    public function attempt(string $username, string $password): array
    {
        $ip = $this->request->ip();
        if ($this->tooManyAttempts($ip)) {
            return [false, 'Слишком много попыток входа. Подождите ' . self::LOCK_MINUTES . ' минут.', null];
        }

        $admin = Db::one('SELECT * FROM admins WHERE username = ? AND is_active = 1', [$username]);
        $locked = $admin !== null && $admin['locked_until'] !== null && $admin['locked_until'] > Db::now();
        $valid = $admin !== null && !$locked && password_verify($password, (string)$admin['password_hash']);

        Db::insert('login_attempts', [
            'ip' => $ip, 'username' => mb_substr($username, 0, 64),
            'success' => $valid ? 1 : 0, 'created_at' => Db::now(),
        ]);

        if (!$valid) {
            if ($admin !== null && !$locked) {
                $attempts = (int)$admin['failed_attempts'] + 1;
                Db::update('admins', [
                    'failed_attempts' => $attempts,
                    'locked_until' => $attempts >= self::MAX_ATTEMPTS
                        ? gmdate('Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60)
                        : null,
                ], 'id = :id', ['id' => $admin['id']]);
            }
            Logger::warning('panel', 'Неудачная попытка входа', ['username' => $username, 'ip' => $ip]);
            return [false, $locked
                ? 'Учётная запись временно заблокирована, попробуйте позже.'
                : 'Неверный логин или пароль.', null];
        }

        $token = Crypto::randomToken();
        Db::insert('admin_sessions', [
            'id'           => hash('sha256', $token),
            'admin_id'     => $admin['id'],
            'ip'           => $ip,
            'user_agent'   => $this->request->userAgent(),
            'created_at'   => Db::now(),
            'last_seen_at' => Db::now(),
            'expires_at'   => gmdate('Y-m-d H:i:s', time() + self::SESSION_DAYS * 86400),
        ]);
        Db::update('admins', [
            'failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => Db::now(),
        ], 'id = :id', ['id' => $admin['id']]);
        Db::delete('admin_sessions', 'expires_at < ?', [Db::now()]);
        Logger::info('panel', 'Вход в панель', ['username' => $username, 'ip' => $ip]);

        return [true, '', $token];
    }

    public function logout(): void
    {
        $token = $this->sessionToken();
        if ($token !== '') {
            Db::delete('admin_sessions', 'id = ?', [hash('sha256', $token)]);
        }
        $this->admin = null;
    }

    public function cookie(string $token, int $lifetimeDays = self::SESSION_DAYS): array
    {
        return [
            'name'  => self::COOKIE,
            'value' => $token,
            'options' => [
                'expires'  => $token === '' ? time() - 3600 : time() + $lifetimeDays * 86400,
                'path'     => '/',
                'secure'   => $this->request->isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ],
        ];
    }

    private function tooManyAttempts(string $ip): bool
    {
        $since = gmdate('Y-m-d H:i:s', time() - self::LOCK_MINUTES * 60);
        $failed = (int)Db::value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at > ?',
            [$ip, $since]
        );
        return $failed >= self::MAX_ATTEMPTS * 2;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}
