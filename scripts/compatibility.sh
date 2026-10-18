#!/usr/bin/env bash
# Compatibility test of the plugin against a real Shopware version (Docker).
#
#   scripts/compatibility.sh 6.7.15.1      → exit 0 = green, otherwise red; log under $WORKDIR/log.txt
#
# Procedure: Shopware of the given version via Composer (template shopware/production, core pinned to the version) in a
# working directory, MariaDB next to it, runtime shopware/docker-base (PHP + nginx). The plugin arrives as a copy of the
# committed state (git archive HEAD) in custom/plugins. Checked:
#   1. plugin:install --activate runs through
#   2. theme:compile runs through (Twig extensions and snippets are loaded)
#   3. the product page answers 200 and carries the widget placeholder (data-xl0g-delivery-promise)
#   4. /llms.txt 200 and /.well-known/xl0g-delivery.json 200 with valid JSON
#   5. the plugin's unit tests (PHPUnit) run green
# Not checked: the storefront JS (needs Node and a build) and the request to xl0g (no key in the test — without a key
# the plugin deliberately shows nothing; what is checked is that it then breaks nothing).
#
# The working directories live under $HOME (Colima mounts only the home directory) and are deleted at the end,
# unless XL0G_SW_KEEP=1. No credentials: the database is a throwaway instance with fixed test values.
set -uo pipefail

VERSION="${1:?Give the Shopware version, e.g. 6.7.15.1}"
case "$VERSION" in 6.6.*) PHP=8.2 ;; *) PHP=8.3 ;; esac
PHP="${XL0G_SW_PHP:-$PHP}"
REPO="$(cd "$(dirname "$0")/.." && pwd)"
WORKDIR="${XL0G_SW_WORKDIR:-$HOME/go/.sw-compat-$VERSION}"
NAME="sw-compat-$(echo "$VERSION" | tr . -)"
NET="$NAME-net"
PORT="${XL0G_SW_PORT:-18$(printf '%03d' $((RANDOM % 900 + 100)))}"
LOG="$WORKDIR/log.txt"

cleanup() {
    docker rm -f "$NAME-web" "$NAME-db" >/dev/null 2>&1
    docker network rm "$NET" >/dev/null 2>&1
    [ "${XL0G_SW_KEEP:-0}" = "1" ] || rm -rf "$WORKDIR"
}
trap cleanup EXIT
step() { echo "== $*" | tee -a "$LOG"; }
red() { echo "RED: $*" | tee -a "$LOG"; exit 1; }

rm -rf "$WORKDIR"; mkdir -p "$WORKDIR/shop"
: > "$LOG"

step "Shopware $VERSION via Composer (PHP $PHP)"
docker run --rm -v "$WORKDIR/shop:/app" -w /app composer:2 sh -c "
    composer create-project shopware/production . --no-interaction --no-scripts --ignore-platform-reqs -q &&
    composer require -W --no-interaction --no-scripts --ignore-platform-reqs -q \
        shopware/core:$VERSION shopware/storefront:$VERSION shopware/administration:$VERSION" >>"$LOG" 2>&1 \
    || red "Composer could not resolve Shopware $VERSION"

step "Plugin from the committed state"
mkdir -p "$WORKDIR/shop/custom/plugins/Xl0gDeliveryPromise"
git -C "$REPO" archive HEAD | tar -x -C "$WORKDIR/shop/custom/plugins/Xl0gDeliveryPromise"

step "Database and runtime"
docker network create "$NET" >/dev/null
docker run -d --name "$NAME-db" --network "$NET" -e MARIADB_ROOT_PASSWORD=test -e MARIADB_DATABASE=shopware mariadb:11 >/dev/null
for i in $(seq 1 60); do docker exec "$NAME-db" mariadb -uroot -ptest -e 'select 1' >/dev/null 2>&1 && break; sleep 2; done
docker run -d --name "$NAME-web" --network "$NET" -p "127.0.0.1:$PORT:8000" \
    -v "$WORKDIR/shop:/var/www/html" \
    -e DATABASE_URL="mysql://root:test@$NAME-db:3306/shopware" -e APP_URL="http://localhost:$PORT" \
    -e APP_ENV=prod -e APP_SECRET=test-secret-only-for-the-compatibility-test -e INSTANCE_ID=compat \
    "shopware/docker-base:$PHP-nginx" >/dev/null || red "Runtime shopware/docker-base:$PHP-nginx does not start"
console() { docker exec -u root -w /var/www/html "$NAME-web" php -d memory_limit=2G bin/console "$@" >>"$LOG" 2>&1; }

step "Install Shopware"
docker exec -u root -w /var/www/html "$NAME-web" sh -c 'composer run-script post-install-cmd >/dev/null 2>&1 || true; chown -R www-data /var/www/html' >>"$LOG" 2>&1
console system:install --basic-setup --create-database --force || red "system:install"

step "1. Install and activate the plugin"
console plugin:refresh || red "plugin:refresh"
console plugin:install --activate Xl0gDeliveryPromise || red "plugin:install"
step "2. Build the theme"
console theme:compile || red "theme:compile"
console cache:clear || red "cache:clear"
docker exec -u root "$NAME-web" chown -R www-data /var/www/html >/dev/null 2>&1

step "3. Product page"
URL="http://localhost:$PORT"
PDP="$(curl -s "$URL/" | grep -oE 'href="[^"]+/detail/[0-9a-f]{32}"' | head -1 | sed -E 's/href="([^"]+)"/\1/')"
[ -n "$PDP" ] || PDP="$(curl -s "$URL/" | grep -oE "href=\"$URL/[^\"]+/[A-Za-z0-9.-]+\"" | head -1 | sed -E 's/href="([^"]+)"/\1/')"
[ -n "$PDP" ] || red "no product page found on the home page"
code="$(curl -s -o "$WORKDIR/pdp.html" -w '%{http_code}' "$PDP")"
[ "$code" = "200" ] || red "product page $PDP answers $code"
grep -q 'data-xl0g-delivery-promise' "$WORKDIR/pdp.html" || red "product page without the widget placeholder"

step "4. Invitation to agents"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$URL/llms.txt")" = "200" ] || red "/llms.txt"
curl -s "$URL/.well-known/xl0g-delivery.json" | python3 -c 'import sys,json; d=json.load(sys.stdin); assert d.get("protocol")=="xl0g-agent-discovery"' \
    || red "/.well-known/xl0g-delivery.json"

step "5. Unit tests (without the Shopware core, as in docs/DEVELOPMENT.md)"
mkdir -p "$WORKDIR/unit" && cp -R "$REPO/src" "$REPO/tests" "$REPO/phpunit.xml.dist" "$REPO/composer.json" "$WORKDIR/unit/"
python3 -c "import json,sys; p=sys.argv[1]; d=json.load(open(p)); d['require']={}; d.setdefault('autoload-dev',{})['psr-4']={'Xl0g\\\\DeliveryPromise\\\\Tests\\\\':'tests/'}; json.dump(d,open(p,'w'),indent=2)" "$WORKDIR/unit/composer.json"
docker run --rm -v "$WORKDIR/unit:/app" -w /app composer:2 sh -c 'composer install --no-interaction -q && vendor/bin/phpunit' >>"$LOG" 2>&1 || red "PHPUnit"

echo "GREEN: Shopware $VERSION — plugin installed, theme built, product page with widget, invitation to agents, unit tests" | tee -a "$LOG"
