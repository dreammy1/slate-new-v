#!/usr/bin/env bash
#
# Slate — diagnose + fix the 503 from setup-local-apache.sh.
# A 503 on the vhost almost always means Apache's mod_proxy_fcgi couldn't
# reach php-fpm's socket. This finds the REAL socket php-fpm is listening on,
# fixes slate.conf if it's pointing somewhere else, and prints the Apache
# error log line so anything else is visible too.
#
# Run:  bash diagnose-local-apache.sh

set -uo pipefail

HB_PREFIX="$(brew --prefix)"
EXTRA_CONF="$HB_PREFIX/etc/httpd/extra/slate.conf"
LOG_DIR="$HB_PREFIX/var/log/httpd"

echo "== 1. Is php-fpm actually running? =="
if pgrep -fl "php-fpm" >/dev/null 2>&1; then
  pgrep -fl "php-fpm"
else
  echo "NOT RUNNING. Starting it..."
  brew services start php
  sleep 2
  pgrep -fl "php-fpm" || echo "Still not running — run 'brew services info php' and check for errors."
fi

echo
echo "== 2. What is php-fpm actually configured to listen on? =="
PHP_PREFIX="$(brew --prefix php)"
POOL_CONF=$(find "$PHP_PREFIX/etc/php" -name "*.conf" -path "*fpm*" 2>/dev/null | xargs grep -l "^listen" 2>/dev/null | head -1)
if [ -z "$POOL_CONF" ]; then
  POOL_CONF=$(find "$HB_PREFIX/etc" -path "*php-fpm.d/www.conf" 2>/dev/null | head -1)
fi
echo "Pool config: ${POOL_CONF:-<not found>}"
REAL_LISTEN=""
if [ -n "$POOL_CONF" ]; then
  REAL_LISTEN="$(grep -E '^\s*listen\s*=' "$POOL_CONF" | head -1 | sed -E 's/^\s*listen\s*=\s*//' | tr -d '\r')"
  echo "Configured 'listen' value: $REAL_LISTEN"
fi

echo
echo "== 3. Actual socket file(s) on disk =="
find "$PHP_PREFIX/var/run" -name "*.sock" 2>/dev/null
find /opt/homebrew/var/run -name "php*fpm*.sock" 2>/dev/null
find /usr/local/var/run -name "php*fpm*.sock" 2>/dev/null

echo
echo "== 4. What's currently in slate.conf's SetHandler =="
if [ -f "$EXTRA_CONF" ]; then
  grep -n "SetHandler" "$EXTRA_CONF"
else
  echo "slate.conf not found at $EXTRA_CONF — run setup-local-apache.sh first."
  exit 1
fi

echo
echo "== 5. Fixing slate.conf to match reality, if needed =="
FIXED_HANDLER=""
if [[ "$REAL_LISTEN" == /* ]] && [ -S "$REAL_LISTEN" ]; then
  echo "php-fpm is listening on a UNIX socket: $REAL_LISTEN"
  FIXED_HANDLER="proxy:unix:${REAL_LISTEN}|fcgi://localhost"
elif [[ "$REAL_LISTEN" =~ ^([0-9.]+|\*)?:?([0-9]+)$ ]]; then
  TCP_PORT="${BASH_REMATCH[2]}"
  echo "php-fpm is listening on TCP port: $TCP_PORT"
  FIXED_HANDLER="proxy:fcgi://127.0.0.1:${TCP_PORT}"
else
  echo "Could not determine php-fpm's real listen address from config — leaving slate.conf as-is."
  echo "Manually check: cat \"$POOL_CONF\" | grep listen"
fi

if [ -n "$FIXED_HANDLER" ]; then
  CURRENT=$(grep "SetHandler" "$EXTRA_CONF" | sed -E 's/.*SetHandler "([^"]+)".*/\1/')
  if [ "$CURRENT" != "$FIXED_HANDLER" ]; then
    cp "$EXTRA_CONF" "${EXTRA_CONF}.bak"
    sed -i '' -E "s#SetHandler \"[^\"]+\"#SetHandler \"${FIXED_HANDLER}\"#" "$EXTRA_CONF"
    echo "Updated slate.conf:"
    echo "  was: $CURRENT"
    echo "  now: $FIXED_HANDLER"
    echo "Restarting Apache..."
    brew services restart httpd
    sleep 2
  else
    echo "slate.conf already matches — the 503 must be something else. See the error log below."
  fi
fi

echo
echo "== 6. Last 20 lines of Apache's error log for this vhost =="
if [ -f "$LOG_DIR/slate-error.log" ]; then
  tail -20 "$LOG_DIR/slate-error.log"
else
  echo "No slate-error.log yet at $LOG_DIR"
fi

echo
echo "== 7. Retest =="
curl -sS -o /dev/null -w "HTTP %{http_code}\n" http://localhost:8080/slate/admin/ || true
