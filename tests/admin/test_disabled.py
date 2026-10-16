"""Without admin_password_hash and admin_token_sha256 in config.php."""

import pytest

from admin_client import TokenClient

pytestmark = pytest.mark.disabled


def test_page_disabled_without_hash(anon):
    r = anon.get()
    assert r.status == 403, r
    assert "admin_password_hash" in r.text
    r = anon.post({"action": "login", "password": "x", "csrf": "x"})
    assert r.status == 403, r
    assert anon.session_id() is None  # no session is started at all


@pytest.mark.parametrize("path", ["/v1/admin/capabilities", "/v1/admin/posts", "/v1/admin", "/v1/admin/nope"])
def test_api_absent_without_token(base_url, path):
    c = TokenClient(base_url, "anything")
    for tok in ("anything", ""):
        r = c.request("GET", path, token=tok)
        assert r.status == 404, r
        assert r.json == {"detail": "Not Found", "code": "not_found"}
    assert c.request("PUT", "/v1/admin/maintenance", body={"maintenance": True}).status == 404
