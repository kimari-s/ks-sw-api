<?php
// Settings for `make test` only. Never use them on a real server: the
// suite needs X-Forwarded-For trusted, and the admin password and token
// are public.
//
// Environment (php -S runs only; mod_php does not see -e variables):
//   KSSW_TEST_ADMIN_DISABLED=1   no admin_password_hash and no admin token
//                                (admin.php answers 403, /v1/admin/* 404)
//   KSSW_TEST_ADMIN_HTTPS=1      the admin API refuses plain http
//   KSSW_TEST_BACKUP_METHOD=copy force the file-copy backup path
//   KSSW_TEST_FORWARDED=trusted|untrusted
//                                trusted_proxies set, trusted_proxy_https
//                                off, admin API needs HTTPS; the test client
//                                is (trusted) or is not (untrusted) a proxy
//                                (tests/admin/test_forwarded_proto.py)
//
// Request header X-Kssw-Test-Fault (tests/admin/test_errors.py) makes this
// file misbehave, standing in for a broken host or a bug:
//   warning  a PHP warning (must not reach the response)
//   fatal    a fatal error, memory exhausted (must become a JSON 500)

$fault = $_SERVER['HTTP_X_KSSW_TEST_FAULT'] ?? '';
if ($fault === 'warning') {
    trigger_error('kssw test warning <b>leak</b>', E_USER_WARNING);
} elseif ($fault === 'fatal') {
    ini_set('memory_limit', '16M');
    $GLOBALS['kssw_test_ballast'] = str_repeat('x', 64 * 1024 * 1024);
}

$disabled = (bool) getenv('KSSW_TEST_ADMIN_DISABLED');
// KSSW_TEST_TRUSTED_PROXY=1: the test client stands in for a TLS-terminating
// proxy in front of the host (XREA's shared SSL): it is trusted by address,
// X-Forwarded-For is read from the right, and its requests count as HTTPS.
$proxy = (bool) getenv('KSSW_TEST_TRUSTED_PROXY');
$forwarded = (string) getenv('KSSW_TEST_FORWARDED');
// Where the test client connects from (docker's networks, the host).
$local = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.1', '::1', 'fd00::/8'];
if ($proxy || $forwarded === 'trusted') {
    $trusted = $local;
} elseif ($forwarded === 'untrusted') {
    $trusted = ['192.0.2.1']; // TEST-NET-1: never the test client
} else {
    $trusted = [];
}
return [
    'db_path' => '/tmp/kssw-test.sqlite3',
    'trust_forwarded_for' => !$trusted,
    'trusted_proxies' => $trusted,
    'trusted_proxy_https' => $proxy,
    'cors_origins' => ['*'],
    // The app directory is mounted read-only in the test containers.
    'maintenance_file' => '/tmp/kssw-test-maintenance',
    // password_hash('test-admin-password', PASSWORD_DEFAULT)
    'admin_password_hash' => $disabled ? '' : '$2y$10$D4CDER40Q5tWDvtcfYb2gOu58b5Zbz9qnASDo8Nlf.1V6.A52LWE.',
    // hash('sha256', 'kssw-test-admin-token-public-never-use')
    'admin_token_sha256' => $disabled ? '' : 'cc39fbb136432b98b6a367163d2ba08159c3132e083c1fa7eb5ec15890cfe7d0',
    // The tests reach the server over plain http from outside the container.
    'admin_allow_http' => !getenv('KSSW_TEST_ADMIN_HTTPS') && !$trusted,
    'admin_backup_method' => getenv('KSSW_TEST_BACKUP_METHOD') ?: 'auto',
];
