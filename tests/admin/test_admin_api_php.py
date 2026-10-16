"""The admin API, PHP specifics: the failed-auth rate limit and its sharing
with page logins, response headers, the SQLite backup."""

import sqlite3
import uuid

import pytest

from admin_client import AdminClient, TokenClient

pytestmark = pytest.mark.enabled


def test_rate_limit_per_ip(base_url, admin_token):
    c = TokenClient(base_url, admin_token)
    for _ in range(10):
        assert c.request("GET", "/v1/admin/capabilities", token="wrong").status == 401
    r = c.request("GET", "/v1/admin/capabilities")  # even the right token
    assert r.status == 429, r
    assert r.json["code"] == "rate_limited"
    assert 0 < int(r.headers["Retry-After"]) <= 900
    assert TokenClient(base_url, admin_token).request("GET", "/v1/admin/capabilities").status == 200  # another IP


def test_page_failures_count_for_the_api(base_url, password, admin_token):
    browser = AdminClient(base_url)
    for _ in range(5):
        assert browser.login("wrong").status == 401
    c = TokenClient(base_url, admin_token, ip=browser.ip)
    for _ in range(5):
        assert c.request("GET", "/v1/admin/log", token="wrong").status == 401
    assert c.request("GET", "/v1/admin/log").status == 429


def test_headers(admin_api):
    r = admin_api.request("GET", "/v1/admin/capabilities", headers={"Origin": "https://evil.example"})
    assert r.headers["Content-Type"] == "application/json; charset=utf-8"
    assert "no-store" in r.headers["Cache-Control"]
    assert r.headers.get("X-Content-Type-Options") == "nosniff"
    assert "Access-Control-Allow-Origin" not in r.headers  # no CORS, whatever cors_origins says


def test_token_compare(admin_api, admin_token):
    assert admin_api.request("GET", "/v1/admin/capabilities", token=admin_token.upper()).status == 401
    r = admin_api.request("GET", "/v1/admin/capabilities", token="", headers={"Authorization": "bearer " + admin_token})
    assert r.status == 200, r  # the scheme name is case-insensitive


def test_backup_is_sqlite(api, admin_api, tmp_path):
    t = "adm-" + uuid.uuid4().hex[:10]
    p = api.create("in the backup " + t, tags=[t])
    r = admin_api.request("GET", "/v1/admin/backup")
    assert r.status == 200 and r.headers["X-Backup-Format"] == "sqlite3"
    assert r.raw.startswith(b"SQLite format 3\x00")
    f = tmp_path / "b.sqlite3"
    f.write_bytes(r.raw)
    con = sqlite3.connect(str(f))
    try:
        assert con.execute("PRAGMA integrity_check").fetchone()[0] == "ok"
        assert con.execute("SELECT text FROM posts WHERE id = ?", (p["id"],)).fetchone() == ("in the backup " + t,)
    finally:
        con.close()
