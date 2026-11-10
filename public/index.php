<?php

declare(strict_types=1);

/*
 * Ön denetleyici: her istek buradan girer.
 * public/index.php -> Kernel -> Router -> Controller -> Service -> Repository
 */

// PHP yerleşik sunucusu (composer serve): var olan dosyaları (css) doğrudan sun
if (PHP_SAPI === 'cli-server') {
    $requested = __DIR__ . parse_url(is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
    if (is_file($requested)) {
        return false;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

$config = require dirname(__DIR__) . '/config/app.php';
$container = App\Core\Container::fromConfig($config);

(new App\Core\Kernel($container))
    ->handle(App\Core\Request::fromGlobals())
    ->send();
