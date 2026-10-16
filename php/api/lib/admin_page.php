<?php
// The minimal HTML admin page (admin.php): log in with a password, list
// recent posts, delete / undelete. Everything else (search, maintenance,
// backup, the admin log) is in the admin API and its CLI (tools/kssw-admin).
// Server-rendered forms; works without JavaScript. The logic is AdminOps.
//
// Until admin_password_hash is set, the page is a setup form instead: the
// operator types a password twice and gets the exact config.php line with
// its hash, to paste in by hand. It writes no file and keeps nothing (only
// the rate limiter's counter): setting up needs no Python, no PHP on the
// operator's machine and no shell.

declare(strict_types=1);

final class AdminPage
{
    public const PAGE_SIZE = 50;
    public const LOGIN_MAX_FAILURES = 5;
    public const EXCERPT_LEN = 120;
    // The setup form: minimum password length in characters, maximum in
    // bytes (bcrypt, PASSWORD_DEFAULT, reads at most 72 bytes), and posts
    // allowed per client IP per AdminOps::AUTH_WINDOW_SEC (each computes a
    // bcrypt hash, so they are limited; a separate counter from logins).
    public const SETUP_MIN_CHARS = 12;
    public const SETUP_MAX_BYTES = 72;
    public const SETUP_MAX_POSTS = 10;
    // The line config.example.php ships, which the setup line replaces.
    private const SETUP_EMPTY_LINE = "'admin_password_hash' => '',";

    /** @var array<string, mixed> */
    private $cfg;
    /** @var Request */
    private $req;
    /** @var AdminOps */
    private $ops;

    public function __construct(array $cfg, Request $req)
    {
        $this->cfg = $cfg;
        $this->req = $req;
        $this->ops = new AdminOps($cfg, 'html');
    }

    public function run(): void
    {
        self::securityHeaders();
        try {
            $this->dispatch();
        } catch (HttpError $e) {
            $this->page($e->status, 'Error', '<p>' . self::h($e->detail) . '</p><p><a href="admin.php">Back</a></p>');
        }
    }

    private function dispatch(): void
    {
        if ($this->cfg['admin_password_hash'] === '') {
            $this->setup();
            return;
        }
        $this->startSession();
        $method = $this->req->method;
        if ($method === 'POST') {
            $this->handlePost();
            return;
        }
        if ($method !== 'GET' && $method !== 'HEAD') {
            header('Allow: GET, HEAD, POST');
            $this->page(405, 'Method Not Allowed', '<p>Method not allowed.</p>');
            return;
        }
        if (!$this->loggedIn()) {
            $this->loginPage(200, '');
            return;
        }
        $this->listing();
    }

    // ---- plumbing ----------------------------------------------------

    private static function securityHeaders(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    }

    private function startSession(): void
    {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/admin.php')));
        session_name('kssw_admin');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => rtrim($dir, '/') . '/',
            'secure' => $this->req->https(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
        if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        if (!empty($_SESSION['admin'])) {
            $idle = time() - (int) ($_SESSION['seen'] ?? 0);
            if ($idle > 60 * (int) $this->cfg['admin_session_minutes']) {
                unset($_SESSION['admin'], $_SESSION['seen']);
                $_SESSION['flash'] = 'Session expired; log in again.';
            } else {
                $_SESSION['seen'] = time();
            }
        }
    }

    private function loggedIn(): bool
    {
        return !empty($_SESSION['admin']);
    }

    private function redirect(int $page = 1): void
    {
        header('Location: admin.php' . ($page > 1 ? '?page=' . $page : ''), true, 303);
    }

    private static function h(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function csrfField(): string
    {
        return '<input type="hidden" name="csrf" value="' . self::h((string) $_SESSION['csrf']) . '">';
    }

    private function time(?string $out): string
    {
        return $out === null ? '' : str_replace('T', ' ', substr($out, 0, 19));
    }

    // ---- POST --------------------------------------------------------

    private function handlePost(): void
    {
        $token = $_POST['csrf'] ?? '';
        if (!is_string($token) || $token === '' || !hash_equals((string) $_SESSION['csrf'], $token)) {
            $this->page(400, 'Bad request', '<p>Invalid or missing CSRF token. Reload the page and try again.</p><p><a href="admin.php">Back</a></p>');
            return;
        }
        $action = $_POST['action'] ?? '';
        $action = is_string($action) ? $action : '';
        if ($action === 'login') {
            $this->login();
            return;
        }
        if (!$this->loggedIn()) {
            $this->loginPage(403, 'Log in first.');
            return;
        }
        switch ($action) {
            case 'logout':
                $this->ops->log('logout', null, false, 'ok');
                $_SESSION = [];
                session_regenerate_id(true);
                $this->redirect();
                return;
            case 'delete':
            case 'undelete':
                $this->setStatus($action === 'delete' ? 'delete' : 'restore');
                return;
        }
        $this->page(400, 'Bad request', '<p>Unknown action.</p><p><a href="admin.php">Back</a></p>');
    }

    private function login(): void
    {
        $ipHmac = $this->ops->ipHmac($this->req->ip);
        list($fails, $wait) = $this->ops->failures($ipHmac);
        if ($fails >= self::LOGIN_MAX_FAILURES) {
            $minutes = max(1, (int) ceil($wait / 60));
            header('Retry-After: ' . ($minutes * 60));
            $this->loginPage(429, "Too many failed logins. Try again in $minutes minute(s).");
            return;
        }
        $pw = $_POST['password'] ?? '';
        if (is_string($pw) && $pw !== '' && password_verify($pw, (string) $this->cfg['admin_password_hash'])) {
            session_regenerate_id(true);
            $_SESSION = ['admin' => true, 'seen' => time(), 'csrf' => bin2hex(random_bytes(32))];
            $this->ops->clearFailures($ipHmac);
            $this->ops->log('login', null, false, 'ok');
            $this->redirect();
            return;
        }
        $this->ops->addFailure($ipHmac);
        $this->loginPage(401, 'Wrong password.');
    }

    private function setStatus(string $action): void
    {
        $page = max(1, (int) (is_string($_POST['page'] ?? null) ? $_POST['page'] : '1'));
        $raw = is_string($_POST['id'] ?? null) ? $_POST['id'] : '';
        $r = $this->ops->setStatus([$raw], $action, false)['results'][0];
        $id = (string) $r['id'];
        switch ($r['result']) {
            case 'changed':
                $_SESSION['flash'] = ($action === 'delete' ? 'Deleted ' : 'Restored ') . $id . '.';
                break;
            case 'unchanged':
                $_SESSION['flash'] = 'Post ' . $id . ' was already ' . ($action === 'delete' ? 'deleted' : 'active') . '.';
                break;
            case 'not_found':
                $_SESSION['flash'] = 'No post ' . $id . '.';
                break;
            default:
                $_SESSION['flash'] = 'Bad post id.';
        }
        $this->redirect($page);
    }

    // ---- setup (no admin_password_hash yet) ---------------------------

    private function setup(): void
    {
        // Like the admin API: the password must not cross the network in
        // clear. Refused before any session or database work.
        if (!$this->cfg['admin_allow_http'] && !$this->req->https() && !$this->req->fromLocalhost()) {
            $this->page(403, 'Admin setup needs HTTPS', '<p class="err">This page sets up the admin password, so it works only over '
                . '<strong>https://</strong>. Open it again with <code>https://</code> at the start of the address.</p>'
                . '<p>If your server has no HTTPS, make the hash on your own computer instead (see the README, "Admin page"): '
                . '<code>php -r \'echo password_hash("your password", PASSWORD_DEFAULT), "\n";\'</code></p>');
            return;
        }
        $this->startSession();
        $method = $this->req->method;
        if ($method === 'POST') {
            $this->setupPost();
            return;
        }
        if ($method !== 'GET' && $method !== 'HEAD') {
            header('Allow: GET, HEAD, POST');
            $this->page(405, 'Method Not Allowed', '<p>Method not allowed.</p>');
            return;
        }
        $this->setupPage(200, '');
    }

    private function setupPost(): void
    {
        $token = $_POST['csrf'] ?? '';
        if (!is_string($token) || $token === '' || !hash_equals((string) $_SESSION['csrf'], $token)) {
            $this->setupPage(400, 'Invalid or missing CSRF token. Try again.');
            return;
        }
        if (($_POST['action'] ?? '') !== 'setup') {
            $this->setupPage(403, 'No admin password is set up yet. Set one up first.');
            return;
        }
        $ipHmac = $this->ops->ipHmac($this->req->ip, 'setup-ip');
        list($posts, $wait) = $this->ops->failures($ipHmac);
        if ($posts >= self::SETUP_MAX_POSTS) {
            $minutes = max(1, (int) ceil($wait / 60));
            header('Retry-After: ' . ($minutes * 60));
            $this->setupPage(429, "Too many attempts. Try again in $minutes minute(s).");
            return;
        }
        $this->ops->addFailure($ipHmac);
        $pw = $_POST['password'] ?? '';
        $pw2 = $_POST['password2'] ?? '';
        if (!is_string($pw) || !is_string($pw2) || $pw === '') {
            $this->setupPage(400, 'Enter the password in both fields.');
            return;
        }
        if (!hash_equals($pw, $pw2)) {
            $this->setupPage(400, 'The two passwords differ. Type the same password twice.');
            return;
        }
        if (ulen($pw) < self::SETUP_MIN_CHARS) {
            $this->setupPage(400, 'The password is too short: use at least ' . self::SETUP_MIN_CHARS . ' characters.');
            return;
        }
        if (strlen($pw) > self::SETUP_MAX_BYTES) {
            $this->setupPage(400, 'The password is too long: at most ' . self::SETUP_MAX_BYTES . ' bytes (72 ASCII characters, about 24 Japanese characters).');
            return;
        }
        $line = "'admin_password_hash' => '" . password_hash($pw, PASSWORD_DEFAULT) . "',";
        $this->setupResult($line);
    }

    private function setupPage(int $status, string $error): void
    {
        $body = '<p>No admin password is set up yet. Choose one here: this page shows the line to put into '
            . '<code>config.php</code>. It saves nothing on the server and does not change any file; you add the line yourself by FTP.</p>';
        if ($error !== '') {
            $body .= '<p class="err">' . self::h($error) . '</p>';
        }
        $body .= '<form method="post" action="admin.php">' . $this->csrfField()
            . '<input type="hidden" name="action" value="setup">'
            . '<p><label>Password <input type="password" name="password" autocomplete="new-password" minlength="' . self::SETUP_MIN_CHARS . '" required autofocus></label></p>'
            . '<p><label>Password again <input type="password" name="password2" autocomplete="new-password" minlength="' . self::SETUP_MIN_CHARS . '" required></label></p>'
            . '<p><small>At least ' . self::SETUP_MIN_CHARS . ' characters. A long phrase you do not use anywhere else is best.</small></p>'
            . '<p><button type="submit">Make the config.php line</button></p></form>'
            . '<p><small>The full administration (search, maintenance mode, backup, the admin log) is the admin command '
            . '<code>kssw-admin</code> on your own computer (Python 3.8+); see the README.</small></p>';
        $this->page($status, 'Set up the admin password', $body);
    }

    private function setupResult(string $line): void
    {
        $body = '<p>Done. Copy this whole line (select it all, then copy):</p>'
            . '<p><textarea readonly rows="2" cols="80" spellcheck="false" style="width:100%;font-family:ui-monospace,monospace">'
            . self::h($line) . '</textarea></p>'
            . '<p>Nothing has been saved yet. Now put the line into <code>config.php</code>:</p><ol>'
            . '<li>With your FTP program, open the folder this page is in (the <code>api</code> folder, next to <code>admin.php</code>). '
            . 'If it has no <code>config.php</code>, download <code>config.example.php</code> and keep the downloaded copy under the name <code>config.php</code>.</li>'
            . '<li>Open <code>config.php</code> in a text editor. Find the line <code>' . self::h(self::SETUP_EMPTY_LINE) . '</code> '
            . 'and replace that whole line with the line above. Save it as UTF-8.</li>'
            . '<li>Upload <code>config.php</code> into the same folder (overwrite it if the server has one).</li>'
            . '<li>Reload this page. The login form appears: log in with the password you just typed.</li></ol>'
            . '<p><small>The password itself is not in the line, only its hash. Each try makes a different line; any of them works. '
            . 'To change the password later, set the value back to <code>' . self::h("''") . '</code> in <code>config.php</code> and open this page again.</small></p>'
            . '<p><a href="admin.php">Back</a></p>';
        $this->page(200, 'Your config.php line', $body);
    }

    // ---- pages -------------------------------------------------------

    private function page(int $status, string $title, string $body): void
    {
        http_response_code($status);
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>' . self::h($title) . ' - ks-sw-api admin</title><style>'
            . 'body{font:14px/1.5 system-ui,sans-serif;margin:1em auto;max-width:72em;padding:0 1em;color:#222;background:#fff}'
            . 'table{border-collapse:collapse;width:100%}td,th{border-bottom:1px solid #ddd;padding:.3em .4em;text-align:left;vertical-align:top}'
            . 'th{background:#f4f4f4}tr.deleted td{color:#999}code,.id{font-family:ui-monospace,monospace;font-size:12px}'
            . '.flash{background:#ffd;border:1px solid #cc9;padding:.5em}.err{background:#fee;border:1px solid #c99;padding:.5em}'
            . '.warn{background:#fdd;border:1px solid #c66;padding:.5em}'
            . 'form.inline{display:inline;margin:0}nav a{margin-right:1em}'
            . '</style></head><body><h1>' . self::h($title) . '</h1>' . $body
            . '<p><small>ks-sw-api ' . self::h(KSSW_IMPL) . ' ' . self::h(KSSW_VERSION) . '</small></p></body></html>';
    }

    private function loginPage(int $status, string $error): void
    {
        $body = '';
        $flash = $this->takeFlash();
        if ($flash !== '') {
            $body .= '<p class="flash">' . self::h($flash) . '</p>';
        }
        if ($error !== '') {
            $body .= '<p class="err">' . self::h($error) . '</p>';
        }
        $body .= '<form method="post" action="admin.php">' . $this->csrfField()
            . '<input type="hidden" name="action" value="login">'
            . '<label>Password <input type="password" name="password" autocomplete="current-password" required autofocus></label> '
            . '<button type="submit">Log in</button></form>';
        $this->page($status, 'Log in', $body);
    }

    private function takeFlash(): string
    {
        $f = isset($_SESSION['flash']) && is_string($_SESSION['flash']) ? $_SESSION['flash'] : '';
        unset($_SESSION['flash']);
        return $f;
    }

    private function listing(): void
    {
        $page = max(1, (int) ($this->req->str('page') ?? '1'));
        list($rows, $total) = $this->ops->listPosts(new AdminPostQuery(), $page, self::PAGE_SIZE);

        $b = '';
        $flash = $this->takeFlash();
        if ($flash !== '') {
            $b .= '<p class="flash">' . self::h($flash) . '</p>';
        }
        if (kssw_maintenance($this->cfg)) {
            $b .= '<p class="warn">Maintenance mode is on: the board refuses new posts.</p>';
        }
        $from = $total === 0 ? 0 : ($page - 1) * self::PAGE_SIZE + 1;
        $to = min($total, $page * self::PAGE_SIZE);
        $b .= '<p>Posts ' . $from . '–' . $to . ' of ' . $total . ', newest first. '
            . '<form class="inline" method="post" action="admin.php">' . $this->csrfField()
            . '<input type="hidden" name="action" value="logout"><button type="submit">Log out</button></form></p>';

        $b .= '<table><thead><tr><th>Time</th><th>Author</th><th>Text</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $id = (string) $r['id'];
            $deleted = $r['status'] !== 'active';
            $text = preg_replace('/\s+/u', ' ', utrim((string) $r['text']));
            $text = is_string($text) ? $text : '';
            $excerpt = uprefix($text, self::EXCERPT_LEN) . (ulen($text) > self::EXCERPT_LEN ? '…' : '');
            $b .= '<tr class="' . ($deleted ? 'deleted' : 'active') . '" id="p-' . self::h($id) . '">'
                . '<td>' . self::h($this->time($r['created_at'])) . '</td>'
                . '<td>' . self::h($r['author'] === null ? '' : (string) $r['author']) . '</td>'
                . '<td>' . self::h($excerpt) . '</td>'
                . '<td>' . ($deleted ? 'deleted' : 'active') . '</td>'
                . '<td><form class="inline" method="post" action="admin.php">' . $this->csrfField()
                . '<input type="hidden" name="action" value="' . ($deleted ? 'undelete' : 'delete') . '">'
                . '<input type="hidden" name="id" value="' . self::h($id) . '">'
                . '<input type="hidden" name="page" value="' . $page . '">'
                . '<button type="submit">' . ($deleted ? 'Undelete' : 'Delete') . '</button></form></td></tr>';
        }
        $b .= '</tbody></table><nav>';
        if ($page > 1) {
            $b .= '<a href="admin.php?page=' . ($page - 1) . '">Newer</a>';
        }
        if ($to < $total) {
            $b .= '<a href="admin.php?page=' . ($page + 1) . '">Older</a>';
        }
        $b .= '</nav>';

        $this->page(200, 'ks-sw-api admin', $b);
    }
}
