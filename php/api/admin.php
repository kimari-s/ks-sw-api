<?php
// ks-sw-api minimal admin page: log in, list recent posts, delete /
// undelete. A fallback for people without the CLI; the full admin surface
// is the admin API (/v1/admin/..., tools/kssw-admin). Specific to this PHP
// implementation; not part of the kssw protocol.
//
// Until admin_password_hash is set in config.php it shows a setup form
// (HTTPS only) that computes the config.php line from a password typed in
// the browser; nothing is written. Or make the hash with PHP:
//            php admin.php hash-password   (reads the password on stdin)
//       or:  php -r 'echo password_hash("your password", PASSWORD_DEFAULT), "\n";'
//
// Like index.php, this file stays parseable by old PHP and checks the
// version first.

if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: text/plain; charset=utf-8', true, 500);
    }
    echo 'PHP 7.4 or later is required (this server runs PHP ' . PHP_VERSION . "); change the PHP version in your hosting control panel\n";
    exit(1);
}

require __DIR__ . '/lib/admin_front.php';
