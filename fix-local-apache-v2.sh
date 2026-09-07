#!/usr/bin/env bash
#
# Slate — fix v2. The previous diagnostic used \s in sed/grep patterns, which
# macOS's built-in (BSD) sed/grep don't support the way GNU's does — it
# silently failed to strip "listen = " and picked up a stale PHP 8.3 config
# instead of the PHP 8.5 one actually running. This version asks the RUNNING
# php-fpm process itself what it's listening on (via lsof), which can't be
# stale or version-confused, then fixes slate.conf to match.
#
# Run:  bash fix-local-apache-v2.sh

set -uo pipefail

HB_PREFIX="$(brew --prefix)"
EXTRA_CONF="$HB_PREFIX/etc/httpd/extra/slate.conf"
LOG_DIR="$HB_PREFIX/var/log/httpd"

echo "== 1. Finding the RUNNING php-fpm master process =="
MASTER_PID="$(pgrep -f 'php-fpm: master' | head -1)"
if [ -z "$MASTER_PID" ]; then
  echo "No php-fpm master process found. Starting it..."
  brew services restart php
  sleep 2
  MASTER_PID="$(pgrep -f 'php-fpm: master' | head -1)"
fi
echo "master PID: ${MASTER_PID:-<none found>}"
ps -p "$MASTER_PID" -o command= 2>/dev/null

if [ -z "$MASTER_PID" ]; then
  echo "Still no php-fpm process — can't continue. Try: brew services info php"
  exit 1
fi

echo
echo "== 2. What is it actually listening on (ground truth, via lsof) =="
LSOF_OUT="$(lsof -Pan -p "$MASTER_PID" -i -U 2>/dev/null || true)"
echo "$LSOF_OUT"

TCP_ENDPOINT=""
UNIX_SOCK=""
# TCP line looks like: php-fpm  123  user  6u  IPv4 ...  TCP 127.0.0.1:9000 (LISTEN)
TCP_LINE="$(echo "$LSOF_OUT" | grep LISTEN | grep -i TCP | head -1)"
if [ -n "$TCP_LINE" ]; then
  TCP_ENDPOINT="$(echo "$TCP_LINE" | awk '{print $NF, $(NF-1)}' | grep -oE '[0-9.]+:[0-9]+' | head -1)"
  if [ -z "$TCP_ENDPOINT" ]; then
    TCP_ENDPOINT="$(echo "$TCP_LINE" | grep -oE '[0-9.]+:[0-9]+' | head -1)"
  fi
fi
# Unix socket line ends with the socket path.
UNIX_LINE="$(echo "$LSOF_OUT" | grep -i unix | head -1)"
if [ -n "$UNIX_LINE" ]; then
  UNIX_SOCK="$(echo "$UNIX_LINE" | awk '{print $NF}')"
fi

FIXED_HANDLER=""
if [ -n "$TCP_ENDPOINT" ]; then
  echo "Found TCP endpoint: $TCP_ENDPOINT"
  FIXED_HANDLER="proxy:fcgi://${TCP_ENDPOINT}"
elif [ -n "$UNIX_SOCK" ] && [ -S "$UNIX_SOCK" ]; then
  echo "Found unix socket: $UNIX_SOCK"
  FIXED_HANDLER="proxy:unix:${UNIX_SOCK}|fcgi://localhost"
else
  echo "Could not determine it from lsof output above — paste this whole"
  echo "script's output back and we'll figure it out from the raw lsof lines."
fi

echo
echo "== 3. Updating slate.conf =="
if [ -n "$FIXED_HANDLER" ] && [ -f "$EXTRA_CONF" ]; then
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
    echo "Already correct — the problem must be something else (see log below)."
  fi
fi

echo
echo "== 4. Retest =="
curl -sS -o /tmp/slate_test.html -w "HTTP %{http_code}\n" http://localhost:8080/slate/admin/

echo
echo "== 5. Last 10 lines of the error log (in case it's still failing) =="
tail -10 "$LOG_DIR/slate-error.log" 2>/dev/null
