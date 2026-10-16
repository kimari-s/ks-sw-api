"""kssw-admin: output, exit codes, every command."""

import hashlib
import json
import os
import re
import sqlite3
import uuid

import pytest


def tag():
    return "cli-" + uuid.uuid4().hex[:10]


# ---- offline -------------------------------------------------------------------


def test_token_new(cli):
    r = cli(["token", "new"], url=False, token=False)
    assert r.code == 0, r
    t = r.json["token"]
    assert re.fullmatch(r"[A-Za-z0-9_-]{43}", t)  # 32 bytes, base64url, no padding
    sha = hashlib.sha256(t.encode()).hexdigest()
    assert r.json["sha256"] == sha
    assert r.json["config_line"] == "'admin_token_sha256' => '%s'," % sha
    assert cli(["token", "new"], url=False, token=False).json["token"] != t


def test_token_hash(cli):
    r = cli(["token", "hash", "--stdin"], stdin="abc\n", url=False, token=False)
    assert r.code == 0 and r.json["sha256"] == hashlib.sha256(b"abc").hexdigest()
    r = cli(["token", "hash"], env={"KSSW_ADMIN_TOKEN": "abc"}, url=False, token=False)
    assert r.json["sha256"] == hashlib.sha256(b"abc").hexdigest()
    assert "abc" not in r.out


def test_commands_describes_everything(cli):
    r = cli(["commands"], url=False, token=False)
    assert r.code == 0, r
    names = {c["name"] for c in r.json["commands"]}
    assert names == {"capabilities", "posts list", "posts get", "posts delete", "posts restore", "maintenance get",
                     "maintenance on", "maintenance off", "backup", "log", "token new", "token hash", "commands"}
    assert set(r.json["exit_codes"]) == {"0", "1", "2", "3", "4", "5"}
    delete = next(c for c in r.json["commands"] if c["name"] == "posts delete")
    assert {"--dry-run", "--stdin", "ids"} <= {a["name"] for a in delete["args"]}
    for c in r.json["commands"]:
        assert c["help"] and c["output"]
        h = cli(c["name"].split() + ["--help"], url=False, token=False)
        assert h.code == 0 and "usage:" in h.out


@pytest.mark.parametrize("args", [[], ["frob"], ["posts"], ["posts", "list", "--page", "x"], ["posts", "delete"]])
def test_usage_errors(cli, args):
    r = cli(args, url=False, token=False)
    assert r.code == 1, r
    if args:
        assert r.error and r.error["code"] in ("usage", "no_ids", "missing_url"), r


def test_missing_url_and_token(cli):
    r = cli(["capabilities"], url=False, token=False)
    assert r.code == 1 and r.error["code"] == "missing_url"
    r = cli(["capabilities"], env={"KSSW_URL": "http://127.0.0.1:9"}, token=False)
    assert r.code == 1 and r.error["code"] == "missing_token"


def test_refuses_plain_http_elsewhere(cli):
    r = cli(["capabilities"], env={"KSSW_URL": "http://example.invalid/api", "KSSW_ADMIN_TOKEN": "x"}, url=False, token=False)
    assert r.code == 1 and r.error["code"] == "http_refused", r


def test_network_error(cli):
    r = cli(["capabilities", "--timeout", "5"], env={"KSSW_URL": "http://127.0.0.1:9", "KSSW_ADMIN_TOKEN": "x"}, url=False, token=False)
    assert r.code == 5 and r.error["code"] == "network", r


# ---- against the server ---------------------------------------------------------


def test_capabilities(cli, server):
    r = cli(["capabilities"])
    assert r.code == 0, r
    assert r.json["version"] == "1" and r.json["impl"] == "php" and "listPosts" in r.json["operations"]
    assert "contract" not in r.json
    assert r.err == ""


def test_global_options_anywhere(cli, server):
    r = cli(["--url", server, "capabilities"], url=False)
    assert r.code == 0, r
    r = cli(["capabilities", "--url", server, "--format", "table"], url=False)
    assert r.code == 0 and "impl_version" in r.out and not r.out.lstrip().startswith("{")


def test_token_file(cli, server, tmp_path):
    f = tmp_path / "tok"
    f.write_text(os.environ["ADMIN_TOKEN"] + "\n")
    assert cli(["capabilities", "--token-file", str(f)], token=False).code == 0


def test_wrong_token(cli, server):
    r = cli(["capabilities"], env={"KSSW_ADMIN_TOKEN": "wrong-" + uuid.uuid4().hex})
    assert r.code == 2 and r.error == {"code": "unauthorized", "message": "invalid token", "status": 401}
    assert r.out == ""


def test_wrong_url(cli, server):
    r = cli(["capabilities"], env={"KSSW_URL": server + "/nowhere"})
    assert r.code == 3 and r.error["code"] == "wrong_url", r


def test_posts_list_get(cli, server, api):
    t = tag()
    a = api.create("cli one " + t, tags=[t], author="cli-a")
    b = api.create("cli two " + t, tags=[t])
    r = cli(["posts", "list", "--tag", t])
    assert r.code == 0, r
    assert [p["id"] for p in r.json["items"]] == [b["id"], a["id"]] and r.json["total"] == 2
    r = cli(["posts", "list", "--tag", t, "--author", "cli-a", "--q", "ONE"])
    assert [p["id"] for p in r.json["items"]] == [a["id"]]
    r = cli(["posts", "list", "--tag", t, "--page-size", "1", "--all"])
    assert [p["id"] for p in r.json["items"]] == [b["id"], a["id"]] and r.json["has_more"] is False
    r = cli(["posts", "list", "--tag", t, "--page-size", "1", "--all", "--limit", "1"])
    assert len(r.json["items"]) == 1 and r.json["has_more"] is True
    r = cli(["posts", "list", "--tag", t, "--format", "table"])
    assert a["id"] in r.out and "total=2" in r.out
    r = cli(["posts", "get", a["id"]])
    assert r.code == 0 and r.json["text"] == "cli one " + t
    r = cli(["posts", "get", str(uuid.uuid4())])
    assert r.code == 3 and r.error["code"] == "post_not_found", r


def test_posts_list_bad_filter(cli, server):
    r = cli(["posts", "list", "--since", "yesterday"])
    assert r.code == 1 and r.error["code"] == "invalid" and r.error["status"] == 422


def test_delete_dry_run_real_restore(cli, server, api):
    t = tag()
    p = api.create("cli delete " + t, tags=[t])
    r = cli(["posts", "delete", "--dry-run", p["id"]])
    assert r.code == 0, r
    assert r.json["dry_run"] is True and r.json["changed"] == 1
    assert api.ok("GET", "/v1/posts/" + p["id"])["status"] == "active"
    r = cli(["posts", "delete", p["id"]])
    assert r.code == 0 and r.json["results"][0]["result"] == "changed"
    assert api.ok("GET", "/v1/posts/" + p["id"])["status"] == "deleted"
    assert cli(["posts", "delete", p["id"]]).json["results"][0]["result"] == "unchanged"
    r = cli(["posts", "restore", p["id"]])
    assert r.code == 0 and api.ok("GET", "/v1/posts/" + p["id"])["status"] == "active"


def test_delete_unknown_ids_exit_3(cli, server, api):
    p = api.create("cli mixed", tags=[tag()])
    r = cli(["posts", "delete", "--dry-run", p["id"], str(uuid.uuid4()), "junk"])
    assert r.code == 3, r
    assert [x["result"] for x in r.json["results"]] == ["changed", "not_found", "invalid_id"]


def test_delete_from_stdin_pipeline(cli, server, api):
    t = tag()
    ids = [api.create("cli bulk %s %d" % (t, i), tags=[t])["id"] for i in range(3)]
    listed = cli(["posts", "list", "--tag", t]).out
    r = cli(["posts", "delete", "--stdin"], stdin=listed)  # a posts list result
    assert r.code == 0 and r.json["changed"] == 3, r
    assert all(api.ok("GET", "/v1/posts/" + i)["status"] == "deleted" for i in ids)
    r = cli(["posts", "restore", "-"], stdin="\n".join(ids) + "\n")  # plain ids
    assert r.code == 0 and r.json["changed"] == 3
    r = cli(["posts", "delete", "--dry-run", "--stdin"], stdin=json.dumps(ids))  # a JSON list
    assert r.code == 0 and r.json["changed"] == 3


def test_delete_batches_over_the_limit(cli, server, api):
    t = tag()
    p = api.create("cli batch " + t, tags=[t])
    many = [str(uuid.uuid4()) for _ in range(150)] + [p["id"]]
    r = cli(["posts", "delete", "--dry-run", "--stdin"], stdin="\n".join(many))
    assert r.code == 3 and len(r.json["results"]) == 151 and r.json["changed"] == 1


def test_maintenance(cli, server, api, maintenance_off):
    assert cli(["maintenance", "get"]).json["maintenance"] is False
    r = cli(["maintenance", "on", "--dry-run"])
    assert r.code == 0 and r.json["dry_run"] is True and r.json["changed"] is True
    assert cli(["maintenance", "get"]).json["maintenance"] is False
    r = cli(["maintenance", "on"])
    assert r.code == 0 and r.json["maintenance"] is True
    assert api.post_json("/v1/posts", {"text": "x"}).status == 503
    assert cli(["maintenance", "on"]).json["changed"] is False
    r = cli(["maintenance", "off"])
    assert r.code == 0 and r.json["maintenance"] is False and r.json["changed"] is True


def test_backup(cli, server, api, tmp_path):
    t = tag()
    p = api.create("cli backup " + t, tags=[t])
    f = tmp_path / "b.sqlite3"
    r = cli(["backup", str(f)])
    assert r.code == 0, r
    assert r.json["file"] == str(f) and r.json["bytes"] == f.stat().st_size
    assert r.json["sha256"] == hashlib.sha256(f.read_bytes()).hexdigest() and r.json["sha256_verified"] is True
    assert r.json["format"] == "sqlite3" and r.json["server_filename"].startswith("kssw-backup-")
    con = sqlite3.connect(str(f))
    try:
        assert con.execute("SELECT text FROM posts WHERE id = ?", (p["id"],)).fetchone() == ("cli backup " + t,)
    finally:
        con.close()
    r = cli(["backup", str(f)])
    assert r.code == 4 and r.error["code"] == "file_exists"
    assert cli(["backup", str(f), "--force"]).code == 0
    assert [x.name for x in tmp_path.iterdir()] == ["b.sqlite3"]  # no temp files left


def test_log(cli, server, api):
    p = api.create("cli log", tags=[tag()])
    cli(["posts", "delete", "--dry-run", p["id"]])
    r = cli(["log", "--action", "delete", "--page-size", "10"])
    assert r.code == 0, r
    e = next(e for e in r.json["items"] if e["target"] == p["id"])
    assert e["dry_run"] is True and e["result"] == "changed" and e["via"] == "api"
