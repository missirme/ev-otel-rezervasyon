<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Mimari kurallar kodla denetlenir (SRS 4.7, NFR-08, NFR-11; ders kuralı: framework yasak):
 * router -> controller -> servis -> repository -> görünüm katmanları atlanmaz, depo uygulaması değiştirilebilir.
 */
#[Group('NFR-08')]
#[Group('NFR-11')]
final class ArchitectureTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** @return array<string, string> göreli yol => içerik */
    private static function sources(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $path = str_replace('\\', '/', $file->getPathname());
                $files[substr($path, strpos($path, $dir) ?: 0)] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($files);

        return $files;
    }

    public function testControllersNeverTalkToRepositoriesDirectly(): void
    {
        // SRS 4.7 "katman atlama yasağı": Controller'dan Repository'ye ok yoktur.
        foreach (self::sources('src/Controller') as $file => $code) {
            self::assertDoesNotMatchRegularExpression('/^use App\\\\Repository\\\\/m', $code, $file);
            self::assertStringNotContainsString('Database', $code, $file);
        }
    }

    public function testViewsOnlyRenderDataTheyAreGiven(): void
    {
        // Görünüm, controller'ın verdiği DTO'ları (App\Service\Dto\...) gösterir; servis, depo veya veritabanı çağırmaz.
        foreach (self::sources('views') as $file => $code) {
            self::assertDoesNotMatchRegularExpression('/Repository|JsonDatabase|\bPDO\b|Container|\w+Service\b/', $code, $file);
        }
    }

    public function testServicesDependOnRepositoryInterfacesOnly(): void
    {
        foreach (self::sources('src/Service') as $file => $code) {
            self::assertDoesNotMatchRegularExpression('/^use App\\\\Repository\\\\Json\\\\/m', $code, $file . ': depo uygulamasına değil arayüzüne bağımlı olmalı');
            self::assertDoesNotMatchRegularExpression('/^use App\\\\(Controller|Core\\\\(Request|Response|Session))\\b/m', $code, $file . ': servis HTTP katmanını bilmez');
        }
    }

    public function testNoDatabaseDriverInPhaseTwo(): void
    {
        // Aşama 2: veriler JSON dosyalarındadır; PDO/MySQL katmanı Aşama 3'te eklenecek.
        foreach (self::sources('src') as $file => $code) {
            self::assertDoesNotMatchRegularExpression('/use PDO|new PDO|PDO::|PDOException|PDOStatement/', $code, $file);
        }
    }

    public function testEveryRepositoryInterfaceHasAJsonImplementation(): void
    {
        $interfaces = glob(self::ROOT . '/src/Repository/*Interface.php') ?: [];
        self::assertNotEmpty($interfaces);
        foreach ($interfaces as $path) {
            $interface = 'App\\Repository\\' . basename($path, '.php');
            $base = str_replace(['RepositoryInterface', 'Interface'], ['Repository', ''], basename($path, '.php'));
            foreach (['Json'] as $driver) {
                $class = 'App\\Repository\\' . $driver . '\\' . $driver . $base;
                self::assertTrue(class_exists($class), $class . ' bulunamadı');
                self::assertTrue((new ReflectionClass($class))->implementsInterface($interface), $class . ' ' . $interface . ' arayüzünü uygulamalı');
            }
        }
    }

    public function testNoFrameworkIsUsed(): void
    {
        // Ders kuralı: framework yasak. Çalışma zamanı bağımlılığı yalnızca PHP ve eklentileridir;
        // geliştirme araçları yalnızca test, statik analiz ve kod stili içindir.
        $composer = json_decode((string) file_get_contents(self::ROOT . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (array_keys($composer['require']) as $package) {
            self::assertMatchesRegularExpression('/^(php|ext-[a-z_]+)$/', $package, 'Çalışma zamanı paketi: ' . $package);
        }
        self::assertSame(['phpstan/phpstan', 'phpunit/phpunit', 'squizlabs/php_codesniffer'], array_keys($composer['require-dev']));
    }
}
