<?php
// The API front controller body, loaded by index.php after its PHP version
// check (index.php itself stays parseable by old PHP versions).

declare(strict_types=1);

// Shared hosts often run with display_errors on: a PHP warning or fatal
// error must never reach the client as HTML (with status 200). Warnings go
// to the error log only; output is buffered so that a fatal error can still
// be turned into a JSON 500.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ob_start();
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e === null || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
        return;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header_remove();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo '{"detail":"internal error","code":"internal"}';
    }
});

require __DIR__ . '/config.php';
require __DIR__ . '/util.php';
require __DIR__ . '/http.php';
require __DIR__ . '/db.php';
require __DIR__ . '/store.php';
require __DIR__ . '/app.php';
require __DIR__ . '/admin.php';
require __DIR__ . '/admin_api.php';

try {
    $cfg = kssw_config(dirname(__DIR__));
    $req = new Request($_SERVER, $cfg['trust_forwarded_for'], $cfg['trusted_proxies'], $cfg['trusted_proxy_https']);
    if (AdminApi::handles($req->path)) {
        (new AdminApi($cfg, $req))->run();
    } else {
        (new App($cfg, $req))->run();
    }
} catch (Throwable $e) {
    error_log('ks-sw-api: ' . $e);
    while (ob_get_level() > 1) {
        ob_end_clean();
    }
    if (ob_get_level() === 1) {
        ob_clean();
    }
    if (!headers_sent()) {
        header_remove();
    }
    send_json(500, ['detail' => 'internal error', 'code' => 'internal'], []);
}
