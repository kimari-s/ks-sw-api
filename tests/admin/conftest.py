"""HTTP tests for the PHP admin page (php/api/admin.php) and other behaviour
specific to the PHP implementation. Not part of the protocol: the kssw
conformance suite (ks-sw-spec, `make spec`) is what every implementation
passes.

    BASE_URL=http://127.0.0.1:8080 ADMIN_PASSWORD=... ADMIN_TOKEN=... pytest tests/admin
    BASE_URL=... pytest tests/admin        # server without admin_password_hash / token

With ADMIN_PASSWORD and ADMIN_TOKEN the server must have that password's
hash and that token's hash configured (tests/config.test.php); without
them, only the "admin disabled" tests run. ADMIN_EXPECT_HTTPS=1: the
server refuses admin requests over plain http (test_https.py). Like the
conformance suite, the server must trust X-Forwarded-For. It writes posts
and toggles maintenance mode: never point it at production.
"""

import os
import sys

import pytest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from client import Api  # noqa: E402
from admin_client import AdminClient, TokenClient  # noqa: E402

PASSWORD = os.environ.get("ADMIN_PASSWORD") or None
TOKEN = os.environ.get("ADMIN_TOKEN") or None


def pytest_configure(config):
    config.addinivalue_line("markers", "enabled: needs ADMIN_PASSWORD (admin configured)")
    config.addinivalue_line("markers", "disabled: runs only without ADMIN_PASSWORD (admin not configured)")


def pytest_collection_modifyitems(config, items):
    for item in items:
        if PASSWORD is None and item.get_closest_marker("enabled"):
            item.add_marker(pytest.mark.skip(reason="ADMIN_PASSWORD not set: admin assumed disabled"))
        if PASSWORD is not None and item.get_closest_marker("disabled"):
            item.add_marker(pytest.mark.skip(reason="ADMIN_PASSWORD set: admin is enabled"))


@pytest.fixture(scope="session")
def base_url():
    base = os.environ.get("BASE_URL")
    if not base:
        pytest.exit("BASE_URL is not set (e.g. BASE_URL=http://127.0.0.1:8080)", returncode=2)
    return base


@pytest.fixture(scope="session")
def api(base_url):
    return Api(base_url)


@pytest.fixture(scope="session")
def password():
    return PASSWORD


@pytest.fixture(scope="session")
def admin_token():
    return TOKEN


@pytest.fixture
def admin_api(base_url):
    """The admin API with the configured token, from its own random IP."""
    return TokenClient(base_url, TOKEN or "")


@pytest.fixture
def anon(base_url):
    """A browser that has not logged in (its own random IP)."""
    return AdminClient(base_url)


@pytest.fixture
def admin(base_url):
    """A logged-in admin browser."""
    c = AdminClient(base_url)
    r = c.login(PASSWORD)
    assert r.status == 303, r
    return c
