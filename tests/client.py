"""Tiny HTTP client for the PHP-specific tests (stdlib urllib only).

A copy of ks-sw-spec's conformance/client.py, kept here so that these tests
do not depend on the suite's internals."""

import itertools
import json
import random
import urllib.error
import urllib.parse
import urllib.request
import uuid


_IP_SPACE = 2 * 256 * 254
_ip_seq = itertools.count(random.randrange(_IP_SPACE))


def random_ip():
    """A client IP no other call in this process got (from a random start,
    so separate runs rarely overlap either): the post cooldown is per IP.
    198.18.0.0/15 is reserved for benchmarking; never a real client."""
    n = next(_ip_seq) % _IP_SPACE
    return "198.%d.%d.%d" % (18 + n // (256 * 254), n // 254 % 256, n % 254 + 1)


class Resp:
    def __init__(self, status, headers, raw):
        self.status = status
        self.headers = headers
        self.raw = raw
        try:
            self.json = json.loads(raw.decode("utf-8")) if raw else None
        except ValueError:
            self.json = None

    def __repr__(self):
        return "<Resp %d %r>" % (self.status, self.raw[:300])


class Api:
    def __init__(self, base_url):
        self.base = base_url.rstrip("/")

    def request(self, method, path, query=None, body=None, headers=None, ip=None):
        url = self.base + path
        if query:
            items = query.items() if isinstance(query, dict) else query
            url += "?" + urllib.parse.urlencode(list(items), doseq=True)
        h = {"Accept": "application/json"}
        # Every request looks like a new visitor unless the test picks the IP.
        # The server must be configured to trust X-Forwarded-For.
        h["X-Forwarded-For"] = ip or random_ip()
        data = None
        if body is not None:
            data = body if isinstance(body, bytes) else json.dumps(body).encode("utf-8")
            h["Content-Type"] = "application/json"
        h.update(headers or {})
        req = urllib.request.Request(url, data=data, method=method, headers=h)
        try:
            with urllib.request.urlopen(req, timeout=30) as r:
                return Resp(r.status, r.headers, r.read())
        except urllib.error.HTTPError as e:
            return Resp(e.code, e.headers, e.read())

    def get(self, path, query=None, **kw):
        return self.request("GET", path, query=query, **kw)

    def post_json(self, path, body, **kw):
        return self.request("POST", path, body=body, **kw)

    def delete(self, path, **kw):
        return self.request("DELETE", path, **kw)

    def ok(self, method, path, **kw):
        r = self.request(method, path, **kw)
        assert r.status == 200, r
        return r.json

    def create(self, text="hello", tags=(), author=None, parent_id=None, client_id=None, ip=None):
        """POST /v1/posts with a fresh client id (unless given); returns the post."""
        body = {"text": text, "tags": list(tags), "client_id": client_id or str(uuid.uuid4())}
        if author is not None:
            body["author"] = author
        if parent_id is not None:
            body["parent_id"] = parent_id
        r = self.post_json("/v1/posts", body, ip=ip)
        assert r.status == 200, r
        post = r.json
        post["_client_id"] = body["client_id"]
        return post
