"""tools/kssw-admin against a live test server.

    BASE_URL=http://127.0.0.1:8080 ADMIN_TOKEN=... pytest tests/cli

BASE_URL must be on localhost (the CLI refuses plain http elsewhere) and
the server must accept ADMIN_TOKEN over plain http (admin_allow_http).
Offline tests (token new / hash, commands, usage) run without a server.
It writes posts and toggles maintenance mode: never point it at
production.
"""

import json
import os
import subprocess
import sys
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from client import Api  # noqa: E402

CLI = Path(__file__).resolve().parent.parent.parent / "tools" / "kssw-admin"
TOKEN = os.environ.get("ADMIN_TOKEN") or None


class Run:
    def __init__(self, p):
        self.code = p.returncode
        self.out = p.stdout
        self.err = p.stderr
        try:
            self.json = json.loads(p.stdout) if p.stdout.strip() else None
        except ValueError:
            self.json = None
        try:
            self.error = json.loads(p.stderr)["error"] if p.stderr.strip() else None
        except (ValueError, KeyError):
            self.error = None

    def __repr__(self):
        return "<Run %d out=%r err=%r>" % (self.code, self.out[:300], self.err[:300])


def run_cli(args, env=None, stdin=None, url=True, token=True):
    e = {k: v for k, v in os.environ.items() if not k.startswith("KSSW_")}
    if url:
        e["KSSW_URL"] = os.environ.get("BASE_URL", "")
    if token and TOKEN:
        e["KSSW_ADMIN_TOKEN"] = TOKEN
    e.update(env or {})
    p = subprocess.run([sys.executable, str(CLI)] + list(args), input=stdin, capture_output=True, text=True, env=e, timeout=60)
    r = Run(p)
    if TOKEN and "token" not in args[:2]:
        assert TOKEN not in r.out and TOKEN not in r.err, "token printed"
    return r


@pytest.fixture
def cli():
    return run_cli


@pytest.fixture
def server():
    if not os.environ.get("BASE_URL") or TOKEN is None:
        pytest.skip("BASE_URL / ADMIN_TOKEN not set")
    return os.environ["BASE_URL"]


@pytest.fixture
def api(server):
    return Api(server)


@pytest.fixture
def maintenance_off(server):
    yield
    assert run_cli(["maintenance", "off"]).code == 0
