"""PHP warnings and fatal errors never reach the client as HTML / 200
(tests/config.test.php misbehaves on request: X-Kssw-Test-Fault). The test
servers run with PHP's default display_errors=1, like many shared hosts."""

import pytest

INTERNAL = {"detail": "internal error", "code": "internal"}
# Responses that never echo post text (which other tests fill with "<b>").
PATHS = ["/v1/meta", "/v1/posts/count", "/v1/admin/capabilities"]


@pytest.mark.parametrize("path", PATHS)
def test_warning_does_not_leak(api, path):
    r = api.get(path, headers={"X-Kssw-Test-Fault": "warning"})
    assert r.status in (200, 401, 403, 404), r
    assert b"kssw test warning" not in r.raw and b"<b>" not in r.raw and b"Warning" not in r.raw
    assert r.json is not None, r
    assert r.headers["Content-Type"].startswith("application/json")


@pytest.mark.parametrize("path", PATHS)
def test_fatal_is_json_500(api, path):
    r = api.get(path, headers={"X-Kssw-Test-Fault": "fatal"})
    assert r.status == 500, r
    assert r.json == INTERNAL, r
    assert r.headers["Content-Type"].startswith("application/json")
    assert b"Fatal" not in r.raw and b"memory" not in r.raw
