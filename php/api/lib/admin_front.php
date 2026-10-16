<?php
// The admin page body, loaded by admin.php after its PHP version check.

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

require __DIR__ . '/config.php';
require __DIR__ . '/util.php';
require __DIR__ . '/http.php';
require __DIR__ . '/db.php';
require __DIR__ . '/store.php';
require __DIR__ . '/app.php';
require __DIR__ . '/admin.php';
require __DIR__ . '/admin_page.php';

if (PHP_SAPI === 'cli') {
    exit(admin_cli($argv));
}

try {
    $cfg = kssw_config(dirname(__DIR__));
    (new AdminPage($cfg, new Request($_SERVER, $cfg['trust_forwarded_for'], $cfg['trusted_proxies'], $cfg['trusted_proxy_https'])))->run();
} catch (Throwable $e) {
    error_log('ks-sw-api admin: ' . $e);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo "Internal Server Error\n";
}
