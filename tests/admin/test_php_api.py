"""What the PHP min implementation advertises and leaves out (beyond the
kssw protocol, which allows other choices)."""

import re

import pytest


@pytest.mark.enabled
def test_meta(api):
    m = api.ok("GET", "/v1/meta")
    assert m["protocol"] == "kssw" and m["version"] == "1"
    assert m["profile"] == "min" and m["impl"] == "php"
    assert m["features"] == ["active_count", "admin", "presence"]
    assert re.match(r"^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$", m["impl_version"]), m["impl_version"]
    assert "contract" not in m


@pytest.mark.disabled
def test_meta_without_admin(api):
    assert api.ok("GET", "/v1/meta")["features"] == ["active_count", "presence"]


def test_unlisted_features_absent(api):
    assert api.get("/v1/links/by-urls", query={"urls": ["https://example.invalid/"]}).status == 404
    assert api.post_json("/v1/tags", {"name": "x"}).status == 405
    # The pre-kssw name of presence is gone.
    assert api.post_json("/v1/access_log", {"session_id": "x"}).status == 404


def test_discovery_at_api_base(api):
    # The document this server would put at the host root (php/well-known/).
    r = api.get("/.well-known/kssw")
    assert r.status == 200, r
    assert r.headers.get("Access-Control-Allow-Origin") == "*"
    d = r.json
    assert d["protocol"] == "kssw" and len(d["apis"]) == 1
    a = d["apis"][0]
    assert a["version"] == "1" and "name" not in a
    # The URL path of the API directory: "/" under php -S, "/api" behind Apache.
    from urllib.parse import urlsplit
    assert a["base"] == (urlsplit(api.base).path or "/"), (a, api.base)


def test_presence_dedupes_per_ip(api):
    ip = "198.19.252.%d" % (__import__("uuid").uuid4().int % 250 + 1)
    before = api.ok("GET", "/v1/active_count")["count"]
    for _ in range(3):
        assert api.post_json("/v1/presence", {}, ip=ip).status == 200
    # One participant, however often it pings (tests run one at a time).
    assert api.ok("GET", "/v1/active_count")["count"] == before + 1
