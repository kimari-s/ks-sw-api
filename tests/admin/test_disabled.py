"""Without admin_password_hash and admin_token_sha256 in config.php."""

import pytest

from admin_client import TokenClient

pytestmark = pytest.mark.disabled


def test_page_setup_without_hash(anon):
    # admin.php shows the password setup form (test_setup.py), no login.
    r = anon.get()
    assert r.status == 200, r
    assert 'value="setup"' in r.text and 'value="login"' not in r.text
    r = anon.post({"action": "login", "password": "x", "csrf": r.csrf()})
    assert r.status == 403, r
    assert 'value="setup"' in r.text


@pytest.mark.parametrize("path", ["/v1/admin/capabilities", "/v1/admin/posts", "/v1/admin", "/v1/admin/nope"])
def test_api_absent_without_token(base_url, path):
    c = TokenClient(base_url, "anything")
    for tok in ("anything", ""):
        r = c.request("GET", path, token=tok)
        assert r.status == 404, r
        assert r.json == {"detail": "Not Found", "code": "not_found"}
    assert c.request("PUT", "/v1/admin/maintenance", body={"maintenance": True}).status == 404
