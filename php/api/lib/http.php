<?php
// Request parsing, errors, JSON responses.

declare(strict_types=1);

final class HttpError extends Exception
{
    /** @var int */
    public $status;
    /** @var string */
    public $detail;
    /** @var array<string, string> */
    public $headers;
    /** @var string|null machine-readable error code (the admin API sends it) */
    public $errorCode;

    public function __construct(int $status, string $detail, array $headers = [], ?string $errorCode = null)
    {
        parent::__construct($detail);
        $this->status = $status;
        $this->detail = $detail;
        $this->headers = $headers;
        $this->errorCode = $errorCode;
    }
}

final class Request
{
    public const MAX_BODY = 65536;

    /** @var string */
    public $method;
    /** @var string e.g. "/v1/posts" */
    public $path;
    /** @var array<string, list<string>> */
    public $query;
    /** @var string|null */
    public $origin;
    /** @var string|null X-Client-Id */
    public $clientId;
    /** @var string */
    public $ip;
    /** @var array<string, mixed> */
    private $server;

    /** @var bool */
    private $viaTrustedProxy = false;
    /** @var bool whether trusted_proxies is configured at all */
    private $hasTrustedProxies = false;
    /** @var bool */
    private $trustedProxyHttps = false;

    /**
     * @param string[] $trustedProxies IPs or CIDRs of proxies in front of this
     *   host (e.g. XREA's shared SSL). Only from these is X-Forwarded-For used.
     */
    public function __construct(array $server, bool $trustForwardedFor, array $trustedProxies = [], bool $trustedProxyHttps = false)
    {
        $this->server = $server;
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        $this->hasTrustedProxies = (bool) $trustedProxies;
        $this->viaTrustedProxy = $remote !== '' && ip_in_any($remote, $trustedProxies);
        $this->trustedProxyHttps = $trustedProxyHttps;
        $this->method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $this->query = self::parseQuery((string) ($server['QUERY_STRING'] ?? ''));
        $this->path = self::routePath($server, $this->query);
        unset($this->query['__route']);
        $this->origin = isset($server['HTTP_ORIGIN']) ? (string) $server['HTTP_ORIGIN'] : null;
        $cid = trim((string) ($server['HTTP_X_CLIENT_ID'] ?? ''));
        $this->clientId = $cid === '' ? null : $cid;
        $ip = '';
        $xff = array_values(array_filter(array_map('trim', explode(',', (string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''))), 'strlen'));
        if ($this->viaTrustedProxy) {
            // The proxy appends the address it saw: walk from the right and
            // take the first hop that is not itself a trusted proxy. Entries
            // further left come from the client and can be forged.
            for ($i = count($xff) - 1; $i >= 0; $i--) {
                if (!ip_in_any($xff[$i], $trustedProxies)) {
                    $ip = $xff[$i];
                    break;
                }
            }
        } elseif ($trustForwardedFor && $xff) {
            $ip = $xff[0];
        }
        $this->ip = $ip !== '' ? $ip : $remote;
    }

    // The Authorization header. Under CGI / FastCGI Apache passes it only
    // through the .htaccess rewrite (E=HTTP_AUTHORIZATION), which renames it
    // REDIRECT_HTTP_AUTHORIZATION after the internal redirect.
    public function authorization(): string
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $k) {
            $v = (string) ($this->server[$k] ?? '');
            if ($v !== '') {
                return $v;
            }
        }
        if (function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $k => $v) {
                if (strtolower((string) $k) === 'authorization') {
                    return (string) $v;
                }
            }
        }
        return '';
    }

    // Whether the client reached us over HTTPS, as far as this host tells:
    // the server's own HTTPS flag, or what a TLS-terminating proxy in front
    // of it says. With trusted_proxies configured, X-Forwarded-Proto and
    // X-Forwarded-Ssl count only from those proxies; without it (proxy
    // address unknown) they count from anyone. (A client lying about it
    // gains little: the check only stops clients that would send the admin
    // token in clear.)
    public function https(): bool
    {
        $s = $this->server;
        $h = strtolower((string) ($s['HTTPS'] ?? ''));
        $forwarded = !$this->hasTrustedProxies || $this->viaTrustedProxy;
        return ($h !== '' && $h !== 'off')
            || ($this->viaTrustedProxy && $this->trustedProxyHttps)
            || strtolower((string) ($s['REQUEST_SCHEME'] ?? '')) === 'https'
            || ($forwarded && strtolower((string) ($s['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
            || ($forwarded && strtolower((string) ($s['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on');
    }

    // The URL path of the directory index.php runs from ("/bbs/api", or
    // "/" at the root), for the discovery document.
    public function basePath(): string
    {
        // Apache / CGI: SCRIPT_NAME is index.php's URL, also after a rewrite
        // from the host root. `php -S` with a router: SCRIPT_NAME is the
        // request path; strip the route from the end of it instead.
        $script = str_replace('\\', '/', (string) ($this->server['SCRIPT_NAME'] ?? ''));
        if (substr($script, -10) === '/index.php') {
            $dir = substr($script, 0, -10);
        } else {
            $uri = rawurldecode((string) parse_url((string) ($this->server['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
            $n = strlen($this->path);
            $dir = substr($uri, -$n) === $this->path ? substr($uri, 0, -$n) : '';
        }
        $dir = rtrim($dir, '/');
        return $dir === '' ? '/' : $dir;
    }

    // A direct connection from this machine (no proxy header).
    public function fromLocalhost(): bool
    {
        $a = (string) ($this->server['REMOTE_ADDR'] ?? '');
        return in_array($a, ['127.0.0.1', '::1'], true) && !isset($this->server['HTTP_X_FORWARDED_FOR']);
    }

    // The API path, from (in order) the __route query parameter the
    // .htaccess rewrite adds, PATH_INFO, or the request URI.
    private static function routePath(array $server, array $query): string
    {
        if (isset($query['__route'][0]) && $query['__route'][0] !== '') {
            return '/' . ltrim($query['__route'][0], '/');
        }
        $pi = (string) ($server['PATH_INFO'] ?? '');
        if ($pi !== '') {
            return $pi;
        }
        $uri = (string) parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $uri = rawurldecode($uri);
        $at = strpos($uri, '/v1/');
        if ($at === false && substr($uri, -3) === '/v1') {
            $at = strlen($uri) - 3;
        }
        return $at === false ? $uri : substr($uri, $at);
    }

    // Repeated names keep every value (PHP's $_GET would keep only the last).
    public static function parseQuery(string $qs): array
    {
        $out = [];
        if ($qs === '') {
            return $out;
        }
        foreach (explode('&', $qs) as $pair) {
            if ($pair === '') {
                continue;
            }
            $kv = explode('=', $pair, 2);
            $k = urldecode($kv[0]);
            $out[$k][] = urldecode($kv[1] ?? '');
        }
        return $out;
    }

    public function has(string $name): bool
    {
        return isset($this->query[$name]);
    }

    // Last value, or null when absent.
    public function str(string $name): ?string
    {
        $v = $this->query[$name] ?? [];
        return $v ? (string) end($v) : null;
    }

    // Every non-empty value of a repeated parameter.
    public function list(string $name): array
    {
        $out = [];
        foreach ($this->query[$name] ?? [] as $v) {
            if ($v !== '') {
                $out[] = $v;
            }
        }
        return $out;
    }

    public function bool(string $name, bool $default = false): bool
    {
        $v = $this->str($name);
        if ($v === null) {
            return $default;
        }
        $v = strtolower(trim($v));
        if (in_array($v, ['true', '1', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($v, ['false', '0', 'no', 'off'], true)) {
            return false;
        }
        throw new HttpError(422, "$name must be a boolean");
    }

    public function int(string $name, ?int $default, ?int $min = null, ?int $max = null): ?int
    {
        $v = $this->str($name);
        if ($v === null) {
            return $default;
        }
        if (!preg_match('/^\s*[+-]?\d{1,15}\s*$/', $v)) {
            throw new HttpError(422, "$name must be an integer");
        }
        $n = (int) trim($v);
        if ($min !== null && $n < $min) {
            throw new HttpError(422, "$name must be at least $min");
        }
        if ($max !== null && $n > $max) {
            throw new HttpError(422, "$name must be at most $max");
        }
        return $n;
    }

    public function op(string $name, string $default): string
    {
        return $this->choice($name, $default, ['and', 'or']);
    }

    public function choice(string $name, string $default, array $choices): string
    {
        $v = $this->str($name);
        if ($v === null) {
            return $default;
        }
        if (!in_array($v, $choices, true)) {
            throw new HttpError(422, "$name must be one of: " . implode(', ', $choices));
        }
        return $v;
    }

    // The body as a JSON object (an associative array). $optional: an
    // empty body counts as {}.
    public function json(bool $optional = false): array
    {
        $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($len > self::MAX_BODY) {
            throw new HttpError(413, 'request body too large');
        }
        $raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
        if (strlen($raw) > self::MAX_BODY) {
            throw new HttpError(413, 'request body too large');
        }
        if ($optional && trim($raw) === '') {
            return [];
        }
        $body = json_decode($raw);
        if (!($body instanceof stdClass)) {
            throw new HttpError(422, 'body must be a JSON object');
        }
        return get_object_vars($body);
    }
}

// A string-or-null body field, trimmed; empty becomes null.
function opt_str(array $body, string $name): ?string
{
    $v = $body[$name] ?? null;
    if ($v === null) {
        return null;
    }
    if (!is_string($v)) {
        throw new HttpError(422, "$name must be a string");
    }
    $v = utrim($v);
    return $v === '' ? null : $v;
}

function send_json(int $status, $body, array $headers): void
{
    $data = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($data === false) {
        $status = 500;
        $data = '{"detail":"internal error","code":"internal"}';
    }
    http_response_code($status);
    foreach ($headers as $k => $v) {
        header("$k: $v");
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen($data));
    echo $data;
}
