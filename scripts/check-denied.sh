#!/bin/sh
# Files that must never be served directly: expect 403 or 404 for each.
#   scripts/check-denied.sh http://127.0.0.1:8780/api
set -u
BASE=$1
fail=0
for p in config.php config.example.php lib/ lib/app.php lib/admin.php lib/admin_api.php lib/admin_page.php lib/schema.sql lib/admin.sql data/ data/maintenance README.md .htaccess; do
	code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/$p")
	case $code in
	403 | 404) echo "denied  $code  $p" ;;
	*) echo "SERVED  $code  $p" >&2; fail=1 ;;
	esac
done
# The DB file itself, wherever it would land by default.
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/data/kssw.sqlite3")
case $code in 403 | 404) echo "denied  $code  data/kssw.sqlite3" ;; *) echo "SERVED  $code  data/kssw.sqlite3" >&2; fail=1 ;; esac
# admin.php must stay reachable (200: login page; 403: admin disabled).
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/admin.php")
case $code in 200 | 403) echo "served  $code  admin.php" ;; *) echo "BLOCKED $code  admin.php" >&2; fail=1 ;; esac
exit $fail
