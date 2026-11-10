<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Oturum yönetimi (NFR-15): çerez HttpOnly ve SameSite=Lax, HTTPS'te Secure;
 * girişte kimlik yenilenir. Bonus: işlem yapılmadan geçen süre dolunca oturum kapanır.
 */
final class Session
{
    private const FLASH = '_flash';
    private const LAST_ACTIVITY = '_last_activity';

    public function __construct(private readonly int $timeoutMinutes = 30)
    {
    }

    public function start(bool $secure = false): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('EVOTELSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $secure,
        ]);
        session_start();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Session fixation saldırısına karşı girişte kimlik yenilenir. */
    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /**
     * Kısmi yenileme parçalarında oturum okunduktan hemen sonra çağrılır: PHP aynı oturumdaki
     * istekleri sıraya soktuğu için bu çağrı olmazsa sayfa donmuş gibi davranır.
     */
    public function close(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public function isTimedOut(int $now): bool
    {
        $last = $this->get(self::LAST_ACTIVITY);

        return is_int($last) && ($now - $last) > $this->timeoutMinutes * 60;
    }

    public function touch(int $now): void
    {
        $this->set(self::LAST_ACTIVITY, $now);
    }

    public function flash(string $type, string $message): void
    {
        $list = $this->get(self::FLASH, []);
        $list = is_array($list) ? $list : [];
        $list[] = ['type' => $type, 'message' => $message];
        $this->set(self::FLASH, $list);
    }

    /** @return list<array{type: string, message: string}> */
    public function takeFlashes(): array
    {
        $list = $this->get(self::FLASH, []);
        $this->remove(self::FLASH);
        $result = [];
        foreach (is_array($list) ? $list : [] as $item) {
            if (is_array($item) && is_string($item['type'] ?? null) && is_string($item['message'] ?? null)) {
                $result[] = ['type' => $item['type'], 'message' => $item['message']];
            }
        }

        return $result;
    }
}
