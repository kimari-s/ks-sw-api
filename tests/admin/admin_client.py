"""A cookie-keeping browser for admin.php (stdlib urllib only; redirects
are not followed, so tests can see the 303 after each POST), and a client
for the admin API with the bearer token."""

import http.cookiejar
import re
import urllib.error
import urllib.parse
import urllib.request

from client import Api, random_ip


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Page:
    def __init__(self, status, headers, raw):
        self.status = status
        self.headers = headers
        self.raw = raw
        self.text = raw.decode("utf-8", "replace")

    def __repr__(self):
        return "<Page %d %r>" % (self.status, self.text[:300])

    def csrf(self):
        m = re.search(r'name="csrf" value="([0-9a-f]+)"', self.text)
        assert m, "no CSRF token in page: %r" % self
        return m.group(1)


class AdminClient:
    def __init__(self, base_url, ip=None):
        self.url = base_url.rstrip("/") + "/admin.php"
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), _NoRedirect)
        self.ip = ip or random_ip()

    def request(self, method, query=None, form=None, headers=None):
        url = self.url
        if query:
            url += "?" + urllib.parse.urlencode(query, doseq=True)
        data = None
        headers = dict({"X-Forwarded-For": self.ip}, **(headers or {}))
        if form is not None:
            data = urllib.parse.urlencode(form).encode("ascii")
            headers["Content-Type"] = "application/x-www-form-urlencoded"
        req = urllib.request.Request(url, data=data, method=method, headers=headers)
        try:
            with self.opener.open(req, timeout=30) as r:
                return Page(r.status, r.headers, r.read())
        except urllib.error.HTTPError as e:
            return Page(e.code, e.headers, e.read())

    def get(self, **query):
        return self.request("GET", query=query or None)

    def post(self, form):
        return self.request("POST", form=form)

    def session_id(self):
        for c in self.jar:
            if c.name == "kssw_admin":
                return c.value
        return None

    def login(self, password):
        token = self.get().csrf()
        return self.post({"csrf": token, "action": "login", "password": password})

    def action(self, action, **fields):
        """POST an action with a fresh CSRF token from the dashboard."""
        token = self.get().csrf()
        form = {"csrf": token, "action": action}
        form.update(fields)
        return self.post(form)


class TokenClient:
    """The admin API as one client IP, with a token ("" sends none)."""

    def __init__(self, base_url, token, ip=None):
        self.api = Api(base_url)
        self.token = token
        self.ip = ip or random_ip()

    def request(self, method, path, query=None, body=None, headers=None, token=None):
        tok = self.token if token is None else token
        h = {"Authorization": "Bearer " + tok} if tok else {}
        h.update(headers or {})
        return self.api.request(method, path, query=query, body=body, headers=h, ip=self.ip)

    def ok(self, method, path, **kw):
        r = self.request(method, path, **kw)
        assert r.status == 200, r
        return r.json
