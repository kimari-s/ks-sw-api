"""admin.php without admin_password_hash: the setup form. It computes the
config.php line from a password typed twice and stores nothing; HTTPS only
(except admin_allow_http / localhost); CSRF token; rate-limited per IP.
With a hash configured the form is gone.

The "disabled" tests run against a server without a password hash
(make test-admin-disabled). ADMIN_EXPECT_HTTPS=1: that server also refuses
plain http (KSSW_TEST_ADMIN_HTTPS=1); the setup tests then send
X-Forwarded-Proto: https, which counts as HTTPS without trusted_proxies.
The hash is checked with the bcrypt package (the Makefile adds it).
"""

import html
import os
import re

import pytest

from admin_client import AdminClient

HTTPS_REQUIRED = os.environ.get("ADMIN_EXPECT_HTTPS") == "1"
HTTPS = {"X-Forwarded-Proto": "https"}
PASSWORD = "correct horse battery staple"
LINE = re.compile(r"'admin_password_hash' => '(\$2y\$[^']+)',")
MIN_CHARS = 12  # AdminPage::SETUP_MIN_CHARS
MAX_POSTS = 10  # AdminPage::SETUP_MAX_POSTS


def browser(base_url):
    return AdminClient(base_url, headers=HTTPS if HTTPS_REQUIRED else None)


def setup_post(c, password, password2=None, csrf=None):
    token = c.get().csrf() if csrf is None else csrf
    return c.post({"csrf": token, "action": "setup", "password": password,
                   "password2": password if password2 is None else password2})


def config_line(page):
    m = LINE.search(html.unescape(page.text))
    return m.group(1) if m else None


def bcrypt_ok(password, hashed):
    bcrypt = pytest.importorskip("bcrypt")
    # $2y$ (PHP) and $2b$ are the same algorithm.
    return bcrypt.checkpw(password.encode("utf-8"), ("$2b$" + hashed[4:]).encode("ascii"))


@pytest.mark.disabled
def test_form_shown_without_hash(base_url):
    r = browser(base_url).get()
    assert r.status == 200, r
    assert 'name="password2"' in r.text and 'value="setup"' in r.text
    assert "config.php" in r.text
    r.csrf()


@pytest.mark.disabled
def test_computes_verifiable_hash(base_url):
    r = setup_post(browser(base_url), PASSWORD)
    assert r.status == 200, r
    hashed = config_line(r)
    assert hashed, r
    assert PASSWORD not in r.text
    assert bcrypt_ok(PASSWORD, hashed)
    assert not bcrypt_ok("wrong " + PASSWORD, hashed)
    # The steps name config.example.php and the line the new one replaces.
    text = html.unescape(r.text)
    assert "config.example.php" in text and "'admin_password_hash' => ''," in text


@pytest.mark.disabled
def test_nothing_stored(base_url):
    # The app directory is mounted read-only in the test containers (so a
    # config.php write would fail), and nothing is kept: the form is still
    # there, a login is still refused, and the next try has another salt.
    c = browser(base_url)
    first = config_line(setup_post(c, PASSWORD))
    r = c.get()
    assert r.status == 200 and 'name="password2"' in r.text, r
    r = c.post({"csrf": r.csrf(), "action": "login", "password": PASSWORD})
    assert r.status == 403, r
    second = config_line(setup_post(browser(base_url), PASSWORD))
    assert first and second and first != second


@pytest.mark.disabled
@pytest.mark.parametrize("csrf", ["", "0" * 64])
def test_csrf_required(base_url, csrf):
    c = browser(base_url)
    c.get()
    r = setup_post(c, PASSWORD, csrf=csrf)
    assert r.status == 400, r
    assert config_line(r) is None
    assert "CSRF" in r.text


@pytest.mark.disabled
@pytest.mark.parametrize("pw,pw2", [
    (PASSWORD, PASSWORD + "x"),     # differ
    ("a" * (MIN_CHARS - 1), None),  # too short
    ("x" * 73, None),               # over bcrypt's 72 bytes
    ("", None),
])
def test_rejected_passwords(base_url, pw, pw2):
    r = setup_post(browser(base_url), pw, pw2)
    assert r.status == 400, r
    assert config_line(r) is None


@pytest.mark.disabled
def test_length_counts_characters(base_url):
    pw = "あ" * MIN_CHARS  # 12 characters, 36 bytes
    r = setup_post(browser(base_url), pw)
    assert r.status == 200, r
    assert bcrypt_ok(pw, config_line(r))


@pytest.mark.disabled
def test_rate_limited(base_url):
    c = browser(base_url)
    for i in range(MAX_POSTS):
        # Every post counts, rejected ones too.
        r = setup_post(c, PASSWORD, PASSWORD + str(i))
        assert r.status == 400, (i, r)
    r = setup_post(c, PASSWORD)
    assert r.status == 429, r
    assert int(r.headers["Retry-After"]) > 0
    assert config_line(r) is None
    # Another IP is not affected.
    assert setup_post(browser(base_url), PASSWORD).status == 200


@pytest.mark.disabled
@pytest.mark.skipif(not HTTPS_REQUIRED, reason="ADMIN_EXPECT_HTTPS not set")
def test_https_required(base_url):
    c = AdminClient(base_url)
    r = c.get()
    assert r.status == 403, r
    assert "https://" in r.text and 'name="password"' not in r.text
    assert c.session_id() is None  # refused before a session starts
    r = c.post({"csrf": "x", "action": "setup", "password": PASSWORD, "password2": PASSWORD})
    assert r.status == 403 and config_line(r) is None, r


@pytest.mark.enabled
def test_gone_with_hash(anon):
    r = anon.get()
    assert r.status == 200, r
    assert 'name="password2"' not in r.text and 'value="login"' in r.text
    r = anon.post({"csrf": r.csrf(), "action": "setup", "password": PASSWORD, "password2": PASSWORD})
    assert r.status in (400, 403), r
    assert config_line(r) is None
