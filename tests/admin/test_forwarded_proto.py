"""X-Forwarded-Proto / X-Forwarded-Ssl with trusted_proxies configured: they
count for the admin API's https check only from a trusted proxy. Runs only
with ADMIN_EXPECT_FORWARDED=untrusted|trusted against a server started with
KSSW_TEST_FORWARDED set to the same value (trusted_proxy_https off, plain
http refused), where the test client is not / is a trusted proxy."""

import os

import pytest

from admin_client import TokenClient

MODE = os.environ.get("ADMIN_EXPECT_FORWARDED")
pytestmark = pytest.mark.skipif(MODE not in ("untrusted", "trusted"), reason="ADMIN_EXPECT_FORWARDED not set")

HEADERS = [{"X-Forwarded-Proto": "https"}, {"X-Forwarded-Ssl": "on"}]


def test_plain_http_refused(base_url, admin_token):
    r = TokenClient(base_url, admin_token).request("GET", "/v1/admin/capabilities")
    assert r.status == 403, r
    assert r.json["code"] == "https_required"


@pytest.mark.parametrize("header", HEADERS)
def test_forwarded_https(base_url, admin_token, header):
    r = TokenClient(base_url, admin_token).request("GET", "/v1/admin/capabilities", headers=header)
    if MODE == "untrusted":
        # A client that is not a trusted proxy cannot claim HTTPS.
        assert r.status == 403, r
        assert r.json["code"] == "https_required"
    else:
        assert r.status == 200, r
