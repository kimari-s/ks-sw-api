"""The admin API over plain http: refused (403 https_required) unless the
server allows it (admin_allow_http, set for the other test runs). Runs only
with ADMIN_EXPECT_HTTPS=1 against a server started with
KSSW_TEST_ADMIN_HTTPS=1."""

import os

import pytest

from admin_client import TokenClient

pytestmark = pytest.mark.skipif(os.environ.get("ADMIN_EXPECT_HTTPS") != "1", reason="ADMIN_EXPECT_HTTPS not set")


def test_plain_http_refused(base_url, admin_token):
    c = TokenClient(base_url, admin_token)
    r = c.request("GET", "/v1/admin/capabilities")
    assert r.status == 403, r
    assert r.json["code"] == "https_required"
    # Refused before the token is looked at.
    assert c.request("GET", "/v1/admin/capabilities", token="wrong").json["code"] == "https_required"


@pytest.mark.parametrize("header", [{"X-Forwarded-Proto": "https"}, {"X-Forwarded-Ssl": "on"}])
def test_https_behind_proxy(base_url, admin_token, header):
    r = TokenClient(base_url, admin_token).request("GET", "/v1/admin/capabilities", headers=header)
    assert r.status == 200, r


def test_public_api_unaffected(api):
    assert api.ok("GET", "/v1/meta")["profile"] == "min"
