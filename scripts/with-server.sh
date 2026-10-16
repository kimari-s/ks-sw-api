#!/bin/sh
# Start a server, wait for GET /v1/meta, run a command, stop the server.
#
#   scripts/with-server.sh BASE_URL START_CMD STOP_CMD TEST_CMD...
#
# START_CMD runs in the background (sh -c). STOP_CMD runs on exit; it may
# use $SERVER_PID. TEST_CMD gets BASE_URL in its environment.
set -u

BASE_URL=$1
START=$2
STOP=$3
shift 3

sh -c "$START" &
SERVER_PID=$!
export SERVER_PID
trap 'sh -c "$STOP" >/dev/null 2>&1' EXIT INT TERM

i=0
until curl -fsS "$BASE_URL/v1/meta" >/dev/null 2>&1; do
	i=$((i + 1))
	if [ "$i" -gt 60 ]; then
		echo "server at $BASE_URL did not come up" >&2
		exit 1
	fi
	sleep 0.5
done

BASE_URL=$BASE_URL "$@"
