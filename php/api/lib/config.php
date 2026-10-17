<?php
// Settings shared by index.php (the API and the admin API) and admin.php.

declare(strict_types=1);

// Settings: config.php in $apiDir (see config.example.php), or the file
// named by the KSSW_CONFIG environment variable. Every key is optional.
function kssw_config(string $apiDir): array
{
    $defaults = [
        'db_path' => $apiDir . '/data/kssw.sqlite3',
        'timezone' => 'Asia/Tokyo',
        'post_cooldown' => 5,
        'cors_origins' => [],
        'trust_forwarded_for' => false,
        'trusted_proxies' => [],
        'trusted_proxy_https' => false,
        'journal_mode' => '',
        'maintenance_file' => $apiDir . '/data/maintenance',
        'admin_password_hash' => '',
        'admin_session_minutes' => 60,
        'admin_backup_method' => 'auto',
        'admin_token_sha256' => '',
        'admin_allow_http' => false,
        'discovery_base' => '',
        'discovery_name' => '',
    ];
    $file = getenv('KSSW_CONFIG');
    if (!is_string($file) || $file === '') {
        $file = $apiDir . '/config.php';
    }
    $cfg = is_file($file) ? require $file : [];
    if (!is_array($cfg)) {
        throw new RuntimeException('config must return an array');
    }
    $cfg += $defaults;
    if (is_string($cfg['cors_origins'])) {
        $cfg['cors_origins'] = array_values(array_filter(array_map('trim', explode(',', $cfg['cors_origins']))));
    }
    $jm = strtoupper((string) $cfg['journal_mode']);
    if ($jm !== '' && !in_array($jm, ['DELETE', 'TRUNCATE', 'PERSIST', 'MEMORY', 'WAL', 'OFF'], true)) {
        throw new RuntimeException('unknown journal_mode');
    }
    $cfg['journal_mode'] = $jm;
    $cfg['trust_forwarded_for'] = (bool) $cfg['trust_forwarded_for'];
    if (is_string($cfg['trusted_proxies'])) {
        $cfg['trusted_proxies'] = explode(',', $cfg['trusted_proxies']);
    }
    $cfg['trusted_proxies'] = array_values(array_filter(array_map('trim', array_map('strval', (array) $cfg['trusted_proxies'])), 'strlen'));
    $cfg['trusted_proxy_https'] = (bool) $cfg['trusted_proxy_https'];
    $cfg['maintenance_file'] = (string) $cfg['maintenance_file'];
    $cfg['admin_password_hash'] = (string) $cfg['admin_password_hash'];
    $cfg['admin_session_minutes'] = max(1, (int) $cfg['admin_session_minutes']);
    // A malformed token hash must not take the board down: the admin API
    // then answers 500 config_invalid, the public API is unaffected.
    $th = strtolower(trim((string) $cfg['admin_token_sha256']));
    $cfg['admin_token_invalid'] = $th !== '' && !preg_match('/^[0-9a-f]{64}$/', $th);
    $cfg['admin_token_sha256'] = $th;
    // The hash of the sample token an earlier README showed: its token is
    // public, so a config.php pasted from that page must not open the admin
    // API (500 config_invalid, like a malformed hash).
    $cfg['admin_token_example'] = $th === 'c3ddb18a7580a45c201233d337cd46750c7278bf5fce759e9f0fb26a3a0a5ccd';
    $cfg['admin_allow_http'] = (bool) $cfg['admin_allow_http'];
    $cfg['discovery_base'] = trim((string) $cfg['discovery_base']);
    $cfg['discovery_name'] = trim((string) $cfg['discovery_name']);
    if (!in_array($cfg['admin_backup_method'], ['auto', 'copy'], true)) {
        throw new RuntimeException('admin_backup_method must be auto or copy');
    }
    return $cfg;
}

// Whether the admin API is enabled (a token hash is configured).
function kssw_admin_api(array $cfg): bool
{
    return $cfg['admin_token_sha256'] !== '';
}

// Maintenance mode is on while this file exists: writes answer 503.
function kssw_maintenance(array $cfg): bool
{
    return $cfg['maintenance_file'] !== '' && is_file($cfg['maintenance_file']);
}
