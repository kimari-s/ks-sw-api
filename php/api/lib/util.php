<?php
// Small helpers: ids, timestamps, Unicode-aware string functions.
//
// Only core extensions are used (PCRE, date, json, hash, PDO). No mbstring:
// code points are counted and trimmed with PCRE in UTF-8 mode.
//
// Timestamps:
//   stored: fixed-width UTC "YYYY-MM-DDTHH:MM:SS.ffffffZ" (sorts as a string)
//   sent:   same instant in the configured zone, "YYYY-MM-DDTHH:MM:SS.ffffff+09:00"

declare(strict_types=1);

const TS_STORE = 'Y-m-d\TH:i:s.u\Z';

function uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
}

// Lowercased UUID, or null when $s is not one.
function norm_uuid(?string $s): ?string
{
    if ($s === null) {
        return null;
    }
    $s = strtolower(trim($s));
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $s) ? $s : null;
}

function utc(): DateTimeZone
{
    static $z = null;
    if ($z === null) {
        $z = new DateTimeZone('UTC');
    }
    return $z;
}

function ts_store(DateTimeInterface $t): string
{
    $d = DateTime::createFromFormat('U.u', $t->format('U.u'));
    if ($d === false) {
        throw new RuntimeException('bad timestamp');
    }
    return $d->setTimezone(utc())->format(TS_STORE);
}

function ts_now(): string
{
    $d = DateTime::createFromFormat('U.u', sprintf('%.6F', microtime(true)));
    if ($d === false) {
        throw new RuntimeException('clock');
    }
    return $d->setTimezone(utc())->format(TS_STORE);
}

function ts_ago(int $seconds): string
{
    $d = DateTime::createFromFormat('U.u', sprintf('%.6F', microtime(true) - $seconds));
    if ($d === false) {
        throw new RuntimeException('clock');
    }
    return $d->setTimezone(utc())->format(TS_STORE);
}

function ts_seconds_since(string $stored): float
{
    $d = DateTime::createFromFormat(TS_STORE, $stored, utc());
    return $d === false ? 0.0 : microtime(true) - (float) $d->format('U.u');
}

function ts_out(?string $stored, DateTimeZone $tz): ?string
{
    if ($stored === null) {
        return null;
    }
    $d = DateTime::createFromFormat(TS_STORE, $stored, utc());
    if ($d === false) {
        return $stored;
    }
    return $d->setTimezone($tz)->format('Y-m-d\TH:i:s.uP');
}

// ISO 8601 date or date-time from a query string, as a stored time; null
// when it does not parse. No offset: the configured zone. A bare date: its
// midnight there. A "+" sent unencoded arrives as a space and is read as "+".
function ts_parse_query(string $s, DateTimeZone $tz): ?string
{
    $re = '/^(\d{4})-(\d{2})-(\d{2})'
        . '(?:[Tt ](\d{2}):(\d{2})(?::(\d{2})(?:[.,](\d{1,6})\d*)?)?)?'
        . '\s*(?:([Zz])|([+\- ])(\d{2}):?(\d{2}))?$/';
    if (!preg_match($re, trim($s), $m)) {
        return null;
    }
    $m = array_pad($m, 12, '');
    list(, $y, $mo, $d, $hh, $mi, $ss, $frac, $z, $sign, $oh, $om) = $m;
    $hh = $hh === '' ? 0 : (int) $hh;
    $mi = $mi === '' ? 0 : (int) $mi;
    $ss = $ss === '' ? 0 : (int) $ss;
    if (!checkdate((int) $mo, (int) $d, (int) $y) || $hh > 23 || $mi > 59 || $ss > 59) {
        return null;
    }
    if ($z !== '') {
        $zone = utc();
    } elseif ($sign !== '') {
        if ((int) $oh > 23 || (int) $om > 59) {
            return null;
        }
        $zone = new DateTimeZone(($sign === '-' ? '-' : '+') . $oh . ':' . $om);
    } else {
        $zone = $tz;
    }
    $text = sprintf('%04d-%02d-%02d %02d:%02d:%02d.%s', (int) $y, (int) $mo, (int) $d, $hh, $mi, $ss, str_pad($frac, 6, '0'));
    $t = DateTime::createFromFormat('Y-m-d H:i:s.u', $text, $zone);
    return $t === false ? null : ts_store($t);
}

// Unicode whitespace (incl. U+3000) at both ends removed.
function utrim(string $s): string
{
    return (string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $s);
}

// Number of code points (input is valid UTF-8: it came from json_decode).
function ulen(string $s): int
{
    return (int) preg_match_all('/./su', $s);
}

// First $n code points.
function uprefix(string $s, int $n): string
{
    return preg_match('/^.{0,' . $n . '}/su', $s, $m) ? $m[0] : $s;
}

// Split on Unicode whitespace (ASCII and full-width spaces).
function usplit(string $s): array
{
    $parts = preg_split('/[\s\p{Z}]+/u', $s, -1, PREG_SPLIT_NO_EMPTY);
    return $parts === false ? [] : $parts;
}

// Whether $ip (IPv4 or IPv6) is one of $list: plain addresses or CIDRs
// ("203.0.113.7", "203.0.113.0/24", "2001:db8::/32"). Invalid entries never
// match.
function ip_in_any(string $ip, array $list): bool
{
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return false;
    }
    foreach ($list as $entry) {
        $entry = trim((string) $entry);
        $bits = null;
        if (strpos($entry, '/') !== false) {
            [$entry, $b] = explode('/', $entry, 2);
            if (!ctype_digit($b)) {
                continue;
            }
            $bits = (int) $b;
        }
        $net = @inet_pton($entry);
        if ($net === false || strlen($net) !== strlen($bin)) {
            continue;
        }
        $len = strlen($bin) * 8;
        $bits = $bits === null ? $len : $bits;
        if ($bits > $len) {
            continue;
        }
        $full = intdiv($bits, 8);
        if (substr($bin, 0, $full) !== substr($net, 0, $full)) {
            continue;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xff << (8 - $rest)) & 0xff;
        if ((ord($bin[$full]) & $mask) === (ord($net[$full]) & $mask)) {
            return true;
        }
    }
    return false;
}
