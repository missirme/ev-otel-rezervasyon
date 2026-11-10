<?php

declare(strict_types=1);

namespace App\Core;

/** CSRF koruması (NFR-14): durum değiştiren her POST isteği oturumdaki jetonla eşleşmelidir. */
final class Csrf
{
    private const KEY = '_csrf';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::KEY);
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::KEY, $token);
        }

        return $token;
    }

    public function verify(string $token): bool
    {
        $expected = $this->session->get(self::KEY);

        return is_string($expected) && $token !== '' && hash_equals($expected, $token);
    }
}
