"""trusted_proxies / trusted_proxy_https: a TLS-terminating proxy in front of
the host (XREA's shared SSL connects over plain http and appends the client
address to X-Forwarded-For). Runs only with ADMIN_EXPECT_PROXY=1 against a
server started with KSSW_TEST_TRUSTED_PROXY=1, where the test client counts
as that proxy."""

import json
import os
import random
import urllib.error
import urllib.request
import uuid

import pytest

pytestmark = pytest.mark.skipif(os.environ.get("ADMIN_EXPECT_PROXY") != "1", reason="ADMIN_EXPECT_PROXY not set")


def _ip():
    return "198.51.100.%d" % random.randint(1, 254) if random.random() < 0.5 else "203.0.113.%d" % random.randint(1, 254)


def _req(base_url, method, path, body=None, headers=None):
    data = json.dumps(body).encode() if body is not None else None
    h = {"Accept": "application/json"}
    if data is not None:
        h["Content-Type"] = "application/json"
    h.update(headers or {})
    req = urllib.request.Request(base_url.rstrip("/") + path, data=data, method=method, headers=h)
    try:
        with urllib.request.urlopen(req, timeout=10) as r:
            return r.status, json.loads(r.read() or b"null")
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read() or b"null")


def _post(base_url, xff):
    body = {"text": "proxy test %s" % uuid.uuid4(), "tags": []}
    return _req(base_url, "POST", "/v1/posts", body, {"X-Forwarded-For": xff, "X-Client-Id": str(uuid.uuid4())})


def test_admin_https_via_trusted_proxy(base_url, admin_token):
    status, doc = _req(base_url, "GET", "/v1/admin/capabilities", headers={"Authorization": "Bearer " + admin_token})
    assert status == 200, doc


def test_client_ip_is_rightmost_hop(base_url):
    # Same real client (rightmost entry), different forged left part and
    # different client ids: the per-IP cooldown still applies.
    real = "192.0.2.%d" % random.randint(1, 254)
    s1, d1 = _post(base_url, "%s, %s" % (_ip(), real))
    assert s1 in (200, 201), d1
    s2, d2 = _post(base_url, "%s, %s" % (_ip(), real))
    assert s2 == 429, d2


def test_distinct_clients_behind_proxy(base_url):
    # Two different real clients behind the same proxy are not throttled
    # together.
    s1, d1 = _post(base_url, "192.0.2.%d" % random.randint(1, 127))
    s2, d2 = _post(base_url, "192.0.2.%d" % random.randint(128, 254))
    assert s1 in (200, 201), d1
    assert s2 in (200, 201), d2
