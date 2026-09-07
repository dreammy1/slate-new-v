#!/usr/bin/env bash
#
# Slate — fix v3. lsof came back empty without sudo (macOS restricts
# inspecting another process's open files/sockets). Instead: read the PHP 8.5
# pool config directly — we know the exact version from the running master
# process (`/opt/homebrew/etc/php/8.5/php-fpm.conf`) — with a grep/sed pattern
# that macOS's built-in BSD tools actually support (v1/v2 used \s, which they
# silently ignore), then confirm with a plain TCP connect test instead of lsof.
#
# Run:  bash fix-local-apache-v3.sh

set -uo pipefail

HB_PREFIX="$(brew --prefix)"
EXTRA_CONF="$HB_PREFIX/etc/httpd/extra/slate.conf"
LOG_DIR="$HB_PREFIX/var/log/httpd"

echo "== 1. Confirm the running php-fpm version =="
MASTER_CMD="$(ps aux | grep '[p]hp-fpm: master')"
echo "$MASTER_CMD"
VERSION_DIR="$(echo "$MASTER_CMD" | grep -oE '/opt/homebrew/etc/php/[0-9]+\.[0-9]+' | head -1)"
if [ -z "$VERSION_DIR" ]; then
  echo "Could not find the version directory in the process command line above."
  echo "Paste this script's full output back."
  exit 1
fi
echo "Running version's config dir: $VERSION_DIR"

echo
echo "== 2. Reading its pool config directly =="
POOL_CONF="$VERSION_DIR/php-fpm.d/www.conf"
echo "Pool config: $POOL_CONF"
if [ ! -f "$POOL_CONF" ]; then
  echo "Not found — listing what's actually there:"
  ls -la "$VERSION_DIR/php-fpm.d/" 2>/dev/null
  exit 1
fi
# Real (uncommented) listen line, using a bracket class for whitespace —
# [[:space:]] is POSIX and works in BSD grep/sed, unlike \s.
LISTEN_LINE="$(grep -E '^[[:space:]]*listen[[:space:]]*=' "$POOL_CONF" | grep -v '^[[:space:]]*;' | head -1)"
echo "Raw line: $LISTEN_LINE"
REAL_LISTEN="$(echo "$LISTEN_LINE" | sed -E 's/^[[:space:]]*listen[[:space:]]*=[[:space:]]*//')"
echo "Parsed value: $REAL_LISTEN"

FIXED_HANDLER=""
if [[ "$REAL_LISTEN" == /* ]]; then
  echo "It's a unix socket path."
  if [ -S "$REAL_LISTEN" ]; then
    echo "  and the socket file exists — good."
  else
    echo "  WARNING: that socket file does not currently exist on disk."
  fi
  FIXED_HANDLER="proxy:unix:${REAL_LISTEN}|fcgi://localhost"
elif [[ "$REAL_LISTEN" =~ ^([0-9.]*|\*)?:([0-9]+)$ ]]; then
  HOST_PART="${BASH_REMATCH[1]}"
  PORT_PART="${BASH_REMATCH[2]}"
  [ -z "$HOST_PART" ] || [ "$HOST_PART" = "*" ] && HOST_PART="127.0.0.1"
  echo "It's TCP: host=$HOST_PART port=$PORT_PART"
  echo "Testing whether that port actually accepts a connection..."
  if command -v nc >/dev/null 2>&1 && nc -z -G 2 "$HOST_PART" "$PORT_PART" 2>/dev/null; then
    echo "  port is open — confirmed."
  else
    echo "  WARNING: could not connect to $HOST_PART:$PORT_PART right now."
  fi
  FIXED_HANDLER="proxy:fcgi://${HOST_PART}:${PORT_PART}"
else
  echo "Unrecognized listen value — paste this output back."
  exit 1
fi

echo
echo "== 3. Updating slate.conf =="
CURRENT="$(grep "SetHandler" "$EXTRA_CONF" | sed -E 's/.*SetHandler "([^"]+)".*/\1/')"
echo "was: $CURRENT"
echo "now: $FIXED_HANDLER"
if [ "$CURRENT" != "$FIXED_HANDLER" ]; then
  cp "$EXTRA_CONF" "${EXTRA_CONF}.bak.$(date +%s)"
  ESCAPED="$(printf '%s\n' "$FIXED_HANDLER" | sed 's/[&/\]/\\&/g')"
  sed -i '' -E "s#SetHandler \"[^\"]+\"#SetHandler \"${ESCAPED}\"#" "$EXTRA_CONF"
  echo "Updated. Restarting Apache..."
  brew services restart httpd
  sleep 2
else
  echo "Already matches — something else must be wrong."
fi

echo
echo "== 4. Retest =="
curl -sS -o /tmp/slate_test.html -w "HTTP %{http_code}\n" http://localhost:8080/slate/admin/
echo "(response body saved to /tmp/slate_test.html if you want to check it)"

echo
echo "== 5. Last 10 lines of the error log =="
tail -10 "$LOG_DIR/slate-error.log" 2>/dev/null
