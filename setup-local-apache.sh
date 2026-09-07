#!/usr/bin/env bash
#
# Slate — local Apache + PHP-FPM + MySQL deployment (macOS / Homebrew).
#
# What this does:
#   1. Installs Apache (httpd) via Homebrew if it isn't already.
#   2. Points Apache's config at your existing Homebrew PHP via php-fpm
#      (it does NOT install a second PHP or a second MySQL — both already
#      exist on this Mac from your earlier `php -S` + install.php run).
#   3. Serves the project under http://localhost:8080/slate/ — matching
#      the RewriteBase already baked into the project's .htaccess, so no
#      application files need editing.
#   4. Updates this checkout's .env APP_URL to match the new address.
#   5. Starts everything and leaves it running via `brew services`, so it
#      survives closing Terminal (and restarts on login/reboot).
#
# Run this from a Terminal window:
#     bash setup_local_apache.sh
#
# Safe to re-run — every step checks before it acts.

set -euo pipefail

PROJECT_DIR="$HOME/Downloads/slate-platform-develop"
PORT=8080

if [ ! -d "$PROJECT_DIR" ]; then
  echo "Can't find $PROJECT_DIR — edit PROJECT_DIR at the top of this script." >&2
  exit 1
fi

if ! command -v brew >/dev/null 2>&1; then
  echo "Homebrew isn't installed. Install it first: https://brew.sh" >&2
  exit 1
fi

echo "== 1. PHP (using what's already installed) =="
if ! command -v php >/dev/null 2>&1; then
  echo "No 'php' command found. Install it first: brew install php" >&2
  exit 1
fi
PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
echo "Found PHP ${PHP_VERSION} at $(command -v php)"

echo "== 2. php-fpm =="
if ! brew list php &>/dev/null; then
  echo "PHP wasn't installed via Homebrew — this script assumes a Homebrew PHP" >&2
  echo "(so it can find php-fpm and its socket path). Adjust manually if yours" >&2
  echo "came from elsewhere (e.g. XAMPP)." >&2
  exit 1
fi
brew services start php >/dev/null 2>&1 || true
PHP_PREFIX="$(brew --prefix php)"
# Homebrew's php-fpm listens on a unix socket under its var/run by default.
FPM_SOCK=$(php -i 2>/dev/null | grep -i "^Loaded Configuration File" >/dev/null; \
  find "$PHP_PREFIX/var/run" -name "php-fpm.sock" 2>/dev/null | head -1 || true)
if [ -z "$FPM_SOCK" ]; then
  FPM_SOCK="$PHP_PREFIX/var/run/php-fpm.sock"
fi
echo "php-fpm socket: $FPM_SOCK"

echo "== 3. Apache (httpd) =="
if ! brew list httpd &>/dev/null; then
  echo "Installing Apache via Homebrew..."
  brew install httpd
fi
# Config/log/var live under the shared Homebrew prefix (e.g. /opt/homebrew/etc/httpd),
# NOT under `brew --prefix httpd` (that's the keg's own opt-symlink, e.g.
# /opt/homebrew/opt/httpd, which has no etc/ of its own) — mixing the two is what
# failed on the first run ("No such file or directory" writing slate.conf).
HB_PREFIX="$(brew --prefix)"
HTTPD_CONF="$HB_PREFIX/etc/httpd/httpd.conf"
EXTRA_DIR="$HB_PREFIX/etc/httpd/extra"
EXTRA_CONF="$EXTRA_DIR/slate.conf"
LOG_DIR="$HB_PREFIX/var/log/httpd"
mkdir -p "$EXTRA_DIR" "$LOG_DIR"

# Homebrew's httpd.conf already has its own `Listen` directive — the install
# caveat set it to 8080 by default so it can run without sudo. Detect it rather
# than assuming, and don't add a second Listen for the same port (Apache logs
# "Address already in use" / refuses to start on a duplicate).
DETECTED_PORT="$(grep -E '^\s*Listen\s+[0-9]+' "$HTTPD_CONF" | head -1 | awk '{print $2}' | tr -d '\r')"
if [ -n "$DETECTED_PORT" ]; then
  PORT="$DETECTED_PORT"
  echo "Using Apache's existing Listen port: $PORT"
else
  echo "Listen ${PORT}" >> "$HTTPD_CONF"
  echo "No existing Listen directive found — added 'Listen ${PORT}' to httpd.conf"
fi

echo "== 4. Writing Slate's vhost config =="
cat > "$EXTRA_CONF" <<EOF
<VirtualHost *:${PORT}>
    Alias /slate "${PROJECT_DIR}"

    <Directory "${PROJECT_DIR}">
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
        DirectoryIndex index.php

        <FilesMatch "\.php\$">
            SetHandler "proxy:unix:${FPM_SOCK}|fcgi://localhost"
        </FilesMatch>
    </Directory>

    ErrorLog "${LOG_DIR}/slate-error.log"
    CustomLog "${LOG_DIR}/slate-access.log" common
</VirtualHost>
EOF
echo "Wrote $EXTRA_CONF"

echo "== 5. Enabling required modules + including the vhost =="
enable_module () {
  local mod="$1" file="$2"
  if grep -qE "^\s*#\s*LoadModule ${mod} " "$HTTPD_CONF"; then
    sed -i '' -E "s|^\s*#\s*(LoadModule ${mod} .*)|\1|" "$HTTPD_CONF"
    echo "  enabled: $mod"
  elif grep -qE "^\s*LoadModule ${mod} " "$HTTPD_CONF"; then
    echo "  already enabled: $mod"
  else
    echo "  WARNING: could not find a LoadModule line for $mod in $HTTPD_CONF — add it manually if the site fails to load." >&2
  fi
}
enable_module "rewrite_module"     "mod_rewrite.so"
enable_module "headers_module"     "mod_headers.so"
enable_module "deflate_module"     "mod_deflate.so"
enable_module "proxy_module"       "mod_proxy.so"
enable_module "proxy_fcgi_module"  "mod_proxy_fcgi.so"

if ! grep -q "Include ${EXTRA_CONF}" "$HTTPD_CONF"; then
  echo "Include ${EXTRA_CONF}" >> "$HTTPD_CONF"
  echo "  added Include for slate.conf"
fi

echo "== 6. Updating .env APP_URL to match =="
ENV_FILE="${PROJECT_DIR}/.env"
NEW_URL="http://localhost:${PORT}/slate"
if [ -f "$ENV_FILE" ]; then
  cp "$ENV_FILE" "${ENV_FILE}.bak.$(date +%s)"
  if grep -q "^APP_URL=" "$ENV_FILE"; then
    sed -i '' "s#^APP_URL=.*#APP_URL=${NEW_URL}#" "$ENV_FILE"
  else
    echo "APP_URL=${NEW_URL}" >> "$ENV_FILE"
  fi
  echo "APP_URL set to ${NEW_URL} (backup saved next to .env)"
else
  echo "No .env found at $ENV_FILE — create one from .env.example first." >&2
fi

echo "== 7. Starting services (persistent — survives reboot/logout) =="
brew services start httpd
brew services start php

echo
echo "Done. If your existing 'php -S 127.0.0.1:8000' dev server is still"
echo "running in another Terminal tab, you can Ctrl+C it now — Apache is"
echo "serving the same project at:"
echo
echo "    ${NEW_URL}/admin/"
echo
echo "Check status any time with:  brew services list"
