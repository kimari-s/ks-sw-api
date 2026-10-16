<?php
// ks-sw-api front controller. .htaccess rewrites <dir>/v1/* here: the API
// (lib/app.php, kssw: see ks-sw-spec) and the admin API under /v1/admin/
// (lib/admin_api.php, the kssw admin API).
//
// Settings: config.php next to this file (see config.example.php), or the
// file named by the KSSW_CONFIG environment variable. Every key is optional.
//
// This file must stay parseable by old PHP (5.x): it checks the version
// before loading code that needs PHP 7.4. Shared hosts often default to an
// older PHP (XREA: 7.0) until the version is changed in the control panel.

if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    header('Content-Type: application/json; charset=utf-8', true, 500);
    header('Cache-Control: no-store');
    echo json_encode(array(
        'detail' => 'PHP 7.4 or later is required (this server runs PHP ' . PHP_VERSION . '); change the PHP version in your hosting control panel',
        'code' => 'php_version',
    ));
    exit;
}

// Under `php -S <host> index.php` (router mode) every request comes here;
// let the built-in server run admin.php itself.
if (PHP_SAPI === 'cli-server' && preg_match('#/admin\.php$#', (string) parse_url(isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH))) {
    return false;
}

require __DIR__ . '/lib/front.php';
