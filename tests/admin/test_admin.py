"""admin.php, the minimal HTML page: login, session, CSRF, rate limit,
listing, delete / undelete. Search, maintenance, backup and the log are in
the admin API (the kssw conformance suite) and not on the page."""

import re
import uuid

import pytest

from admin_client import AdminClient

pytestmark = pytest.mark.enabled


def token():
    return "adm" + uuid.uuid4().hex[:12]


def row_of(page, post_id):
    """The <tr> of a post in the listing, or None."""
    return re.search(r'<tr class="(active|deleted)" id="p-%s">((?:(?!</tr>).)*)</tr>' % re.escape(post_id), page.text, re.S)


# ---- login / session ---------------------------------------------------------


def test_login_page_cookie_and_headers(anon):
    r = anon.get()
    assert r.status == 200, r
    assert 'type="password"' in r.text
    r.csrf()
    cookie = r.headers.get("Set-Cookie", "")
    assert cookie.startswith("kssw_admin="), cookie
    assert "HttpOnly" in cookie
    assert "SameSite=Strict" in cookie
    assert "secure" not in cookie.lower()  # plain http here; Secure is set under HTTPS
    assert r.headers.get("X-Frame-Options") == "DENY"
    assert "frame-ancestors 'none'" in r.headers.get("Content-Security-Policy", "")
    assert "no-store" in r.headers.get("Cache-Control", "")
    assert "<script" not in r.text


def test_secure_cookie_behind_https_proxy(anon):
    r = anon.request("GET", headers={"X-Forwarded-Proto": "https"})
    assert "secure" in r.headers.get("Set-Cookie", "").lower()


def test_login_wrong_then_right(anon, password):
    r = anon.login("wrong-" + password)
    assert r.status == 401, r
    assert "Wrong password" in r.text
    before = anon.session_id()
    r = anon.login(password)
    assert r.status == 303, r
    assert r.headers["Location"].startswith("admin.php")
    assert anon.session_id() != before  # new session id on login (no fixation)
    page = anon.get()
    assert page.status == 200 and "Log out" in page.text


def test_login_needs_csrf(anon, password):
    anon.get()
    for bad in ({}, {"csrf": ""}, {"csrf": "0" * 64}):
        r = anon.post(dict({"action": "login", "password": password}, **bad))
        assert r.status == 400, r
    assert 'type="password"' in anon.get().text  # still logged out


def test_login_rate_limit_per_ip(base_url, password):
    c = AdminClient(base_url)
    for _ in range(5):
        assert c.login("wrong").status == 401
    r = c.login(password)  # even the right password, from this IP
    assert r.status == 429, r
    assert int(r.headers["Retry-After"]) > 0
    # A fresh browser on the same IP is limited too; another IP is not.
    assert AdminClient(base_url, ip=c.ip).login(password).status == 429
    assert AdminClient(base_url).login(password).status == 303


def test_success_resets_failure_count(base_url, password):
    c = AdminClient(base_url)
    for _ in range(4):
        assert c.login("wrong").status == 401
    assert c.login(password).status == 303
    c2 = AdminClient(base_url, ip=c.ip)
    for _ in range(4):
        assert c2.login("wrong").status == 401
    assert c2.login(password).status == 303


def test_logout(admin):
    stale = admin.get().csrf()
    r = admin.action("logout")
    assert r.status == 303, r
    assert 'type="password"' in admin.get().text
    # The old token is no good after logout.
    assert admin.post({"csrf": stale, "action": "delete", "id": str(uuid.uuid4())}).status in (400, 403)


@pytest.mark.parametrize("action", ["delete", "undelete", "logout"])
def test_actions_need_login(api, anon, action):
    p = api.create("still here", tags=[token()])
    r = anon.action(action, id=p["id"])
    assert r.status == 403, r
    assert api.ok("GET", "/v1/posts/" + p["id"])["status"] == "active"


def test_actions_need_csrf(api, admin):
    p = api.create("still here", tags=[token()])
    admin.get()
    for bad in ({}, {"csrf": "f" * 64}):
        r = admin.post(dict({"action": "delete", "id": p["id"]}, **bad))
        assert r.status == 400, r
    assert api.ok("GET", "/v1/posts/" + p["id"])["status"] == "active"


@pytest.mark.parametrize("action", ["drop_everything", "maintenance_on", "maintenance_off", "backup"])
def test_removed_and_unknown_actions(admin, action):
    assert admin.action(action).status == 400


def test_other_methods_405(anon):
    assert anon.request("PUT").status == 405


# ---- the page --------------------------------------------------------------


def test_page_is_minimal(admin):
    page = admin.get()
    assert "Log out" in page.text
    for gone in ('type="search"', 'method="get"', "Download database backup", "Admin log", "maintenance_on", 'value="backup"'):
        assert gone not in page.text, gone


def test_listing_shows_post_fields(api, admin):
    t = token()
    p = api.create("listed %s\nsecond line" % t, tags=[t], author="adm-author")
    m = row_of(admin.get(), p["id"])
    assert m, "newest post not on page 1"
    assert m.group(1) == "active"
    row = m.group(2)
    assert "adm-author" in row
    assert "listed %s second line" % t in row  # whitespace collapsed
    assert re.search(r"\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}", row)


def test_text_is_escaped(api, admin):
    t = token()
    api.create("<b>%s</b> & co" % t, tags=[t])
    page = admin.get()
    assert "&lt;b&gt;%s&lt;/b&gt; &amp; co" % t in page.text
    assert "<b>%s" % t not in page.text


def test_pagination(api, admin):
    t = token()
    ids = [api.create("page %s %d" % (t, i), tags=[t])["id"] for i in range(51)]
    p1 = admin.get()
    assert re.search(r"Posts 1–50 of \d+", p1.text)
    assert "Older" in p1.text and "Newer" not in p1.text
    assert row_of(p1, ids[-1]) and not row_of(p1, ids[0])  # newest first
    p2 = admin.get(page="2")
    assert row_of(p2, ids[0]) and "Newer" in p2.text


def test_delete_and_undelete(api, admin):
    t = token()
    p = api.create("to delete " + t, tags=[t], author="adm")
    r = admin.action("delete", id=p["id"], page="2")
    assert r.status == 303, r
    assert r.headers["Location"] == "admin.php?page=2"  # back to the same page
    got = api.ok("GET", "/v1/posts/" + p["id"])
    assert got["status"] == "deleted" and got["text"] == "" and got["tags"] == []
    page = admin.get()
    assert "Deleted " + p["id"] in page.text
    m = row_of(page, p["id"])
    assert m.group(1) == "deleted" and "Undelete" in m.group(2)
    assert "to delete " + t in m.group(2)  # the admin still sees the text
    admin.action("delete", id=p["id"])
    assert "already deleted" in admin.get().text

    assert admin.action("undelete", id=p["id"]).status == 303
    got = api.ok("GET", "/v1/posts/" + p["id"])
    assert got["status"] == "active"
    assert got["text"] == "to delete " + t and got["tags"] == [t] and got["author"] == "adm"


def test_page_actions_in_admin_log(api, admin, admin_api):
    p = api.create("page log", tags=[token()])
    admin.action("delete", id=p["id"])
    admin.action("undelete", id=p["id"])
    log = admin_api.ok("GET", "/v1/admin/log", query={"page_size": "20"})["items"]
    mine = [(e["action"], e["result"], e["via"]) for e in log if e["target"] == p["id"]]
    assert mine == [("restore", "changed", "html"), ("delete", "changed", "html")]
    assert any(e["action"] == "login" and e["via"] == "html" for e in log)


def test_delete_bad_id(admin):
    admin.action("delete", id="not-a-uuid")
    assert "Bad post id" in admin.get().text


def test_delete_unknown_id(admin):
    i = str(uuid.uuid4())
    admin.action("delete", id=i)
    assert "No post " + i in admin.get().text


def test_deleted_post_cannot_be_deleted_by_owner_again(api, admin):
    p = api.create("owner", tags=[token()])
    admin.action("delete", id=p["id"])
    r = api.delete("/v1/posts/" + p["id"], headers={"X-Client-Id": p["_client_id"]})
    assert r.status == 403


def test_maintenance_notice_and_edits(api, admin, admin_api):
    p = api.create("during maintenance", tags=[token()])
    admin_api.ok("PUT", "/v1/admin/maintenance", body={"maintenance": True})
    try:
        assert "Maintenance mode is on" in admin.get().text
        admin.action("delete", id=p["id"])  # the page keeps working, like the API
        assert api.ok("GET", "/v1/posts/" + p["id"])["status"] == "deleted"
    finally:
        admin_api.ok("PUT", "/v1/admin/maintenance", body={"maintenance": False})
    assert "Maintenance mode is on" not in admin.get().text
