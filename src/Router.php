<?php

declare(strict_types=1);

namespace App;

use App\Model\Role;

/**
 * Adrese göre hangi controller metodunun çalışacağını seçer (tasarim.md bölüm 1).
 * Her rota erişim kuralını da taşır; yetki her istekte sunucuda denetlenir (FR-05, NFR-03).
 *
 * Erişim: null = herkes, 'auth' = giriş yapmış herkes, list<Role> = yalnızca bu roller.
 */
final class Router
{
    /** Rota parametrelerinin biçimleri (ör. {id} yalnızca rakam). */
    private const PARAMS = [
        'id' => '\d+',
        'no' => 'RZ-\d{8}-[A-Z0-9]{6}',
        'token' => '[a-f0-9]{64}',
        'ileti' => '\d{8}-\d{6}-[0-9a-f]{6}', // simüle posta kutusundaki ileti (FileMailer dosya adı)
    ];

    /** @var list<array{method: string, regex: string, handler: array{0: class-string, 1: string}, access: string|list<Role>|null}> */
    private array $routes = [];

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param string|list<Role>|null $access
     */
    public function get(string $pattern, array $handler, string|array|null $access = null): void
    {
        $this->add('GET', $pattern, $handler, $access);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param string|list<Role>|null $access
     */
    public function post(string $pattern, array $handler, string|array|null $access = null): void
    {
        $this->add('POST', $pattern, $handler, $access);
    }

    /**
     * @return array{handler: array{0: class-string, 1: string}, params: list<string>, access: string|list<Role>|null}|string|null
     *         eşleşme, 'method' (adres var ama yöntem yanlış) veya null (adres yok)
     */
    public function match(string $method, string $path): array|string|null
    {
        $pathExists = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            $pathExists = true;
            if ($route['method'] !== $method) {
                continue;
            }
            array_shift($m);

            return ['handler' => $route['handler'], 'params' => array_values($m), 'access' => $route['access']];
        }

        return $pathExists ? 'method' : null;
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param string|list<Role>|null $access
     */
    private function add(string $method, string $pattern, array $handler, string|array|null $access): void
    {
        $regex = preg_replace_callback(
            '/\{(\w+)\}/',
            fn (array $m): string => '(' . (self::PARAMS[$m[1]] ?? '[^/]+') . ')',
            $pattern
        );
        $this->routes[] = ['method' => $method, 'regex' => '#^' . $regex . '$#', 'handler' => $handler, 'access' => $access];
    }
}
