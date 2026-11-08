#!/usr/bin/env bash
# Runs the test suite: PHP lint, JS syntax, PHP unit tests (against tests/server.py) and JS unit tests.
#   bash tests/run.sh
set -euo pipefail
cd "$(dirname "$0")/.."

export LTTS_TEST_TMP="${LTTS_TEST_TMP:-$(mktemp -d)}"
PORT="${LTTS_TEST_PORT:-8899}"
export LTTS_TEST_SERVER="http://127.0.0.1:$PORT"
mkdir -p "$LTTS_TEST_TMP/srv"

echo "== PHP syntax"
find local-tts tests -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null

echo "== JavaScript syntax"
for f in $(find local-tts/assets dev -name '*.js' -o -name '*.mjs'); do node --check "$f"; done

echo "== Source hygiene"
python3 tools/check_source.py

python3 tests/server.py "$LTTS_TEST_TMP/srv" "$PORT" &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT
for _ in $(seq 50); do curl -s -o /dev/null "$LTTS_TEST_SERVER/" && break; sleep 0.1; done

status=0
for t in tests/php/test-*.php; do
  echo "== $t"
  php "$t" || status=1
done

echo "== JavaScript unit tests"
node --test --test-reporter=spec tests/js/*.test.mjs || status=1

exit $status
