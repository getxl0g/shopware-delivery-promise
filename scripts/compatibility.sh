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
#   4. the page view beacon route answers 204; /llms.txt 200 and /.well-known/xl0g-delivery.json 200 with valid JSON
#   5. the plugin's unit tests (PHPUnit) run green
#   6. only if XL0G_TEST_API_URL and XL0G_TEST_API_KEY are set (a test access with promise:read only, kept outside the
#      repository): the product page carries x-xl0g-delivery-date in its JSON-LD and /xl0g/delivery-promise answers
#      with promised_date. Without them the plugin deliberately shows nothing; steps 1–5 check that it then breaks nothing.
# Not checked: the storefront JS (needs Node and a build).
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
VOL="$NAME-shop"   # the shop lives in a Docker volume: tens of thousands of files through the Colima mount lost some (realpath failed)
PORT="${XL0G_SW_PORT:-18$(printf '%03d' $((RANDOM % 900 + 100)))}"
LOG="$WORKDIR/log.txt"

cleanup() {
    docker rm -f "$NAME-web" "$NAME-db" >/dev/null 2>&1
    docker network rm "$NET" >/dev/null 2>&1
    docker volume rm "$VOL" >/dev/null 2>&1
    [ "${XL0G_SW_KEEP:-0}" = "1" ] || rm -rf "$WORKDIR"
}
trap cleanup EXIT
step() { echo "== $*" | tee -a "$LOG"; }
red() {
    echo "RED: $*" | tee -a "$LOG"
    # Shopware's own error log, if the shop is already running — otherwise a 500 says nothing about its cause.
    docker exec "$NAME-web" sh -c 'tail -n 60 /var/www/html/var/log/*.log 2>/dev/null' >>"$LOG" 2>&1
    cp "$LOG" "${TMPDIR:-/tmp}/sw-compat-$VERSION-red.log" 2>/dev/null
    tail -60 "$LOG" >&2
    exit 1
}

rm -rf "$WORKDIR"; mkdir -p "$WORKDIR"
docker volume rm "$VOL" >/dev/null 2>&1; docker volume create "$VOL" >/dev/null
: > "$LOG"

step "Shopware $VERSION via Composer (PHP $PHP)"
# Composer must resolve for the PHP of the runtime ($PHP), not for its own: with --ignore-platform-reqs it installed a
# Symfony release that needs PHP 8.4 (ParseError in the shop). Only PHP extensions are ignored (they are in the runtime).
docker run --rm -v "$VOL:/app" -w /app composer:2 sh -c "
    composer create-project shopware/production . --no-interaction --no-scripts --no-install -q &&
    composer config platform.php $PHP.99 &&
    composer require -W --no-interaction --no-scripts --ignore-platform-req='ext-*' -q \
        shopware/core:$VERSION shopware/storefront:$VERSION shopware/administration:$VERSION" >>"$LOG" 2>&1 \
    || red "Composer could not resolve Shopware $VERSION"

step "Plugin from the committed state"
git -C "$REPO" archive HEAD | docker run -i --rm -v "$VOL:/app" alpine:3 \
    sh -c 'mkdir -p /app/custom/plugins/Xl0gDeliveryPromise && tar -x -C /app/custom/plugins/Xl0gDeliveryPromise' \
    || red "copying the plugin into the shop volume"

step "Database and runtime"
docker network create "$NET" >/dev/null
docker run -d --name "$NAME-db" --network "$NET" -e MARIADB_ROOT_PASSWORD=test -e MARIADB_DATABASE=shopware mariadb:11 >/dev/null
# Over TCP, not the socket: MariaDB restarts once after initialising, and the socket answers before TCP listens for good.
for i in $(seq 1 90); do docker exec "$NAME-db" mariadb -h127.0.0.1 -uroot -ptest -e 'select 1' >/dev/null 2>&1 && break; sleep 2; done
sleep 3
docker run -d --name "$NAME-web" --network "$NET" -p "127.0.0.1:$PORT:8000" \
    -v "$VOL:/var/www/html" \
    -e DATABASE_URL="mysql://root:test@$NAME-db:3306/shopware" -e APP_URL="http://localhost:$PORT" \
    -e APP_ENV=prod -e APP_SECRET=test-secret-only-for-the-compatibility-test -e INSTANCE_ID=compat \
    "shopware/docker-base:$PHP-nginx" >/dev/null || red "Runtime shopware/docker-base:$PHP-nginx does not start"
# As root (the volume belongs to root), then everything back to www-data — the web server writes its cache as www-data.
console() {
    docker exec -u root -w /var/www/html "$NAME-web" php -d memory_limit=2G bin/console "$@" >>"$LOG" 2>&1; local rc=$?
    docker exec -u root "$NAME-web" chown -R www-data /var/www/html/var /var/www/html/public >/dev/null 2>&1
    return $rc
}

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

step "3. Product page (a test product created via the Admin API — a fresh install has no products)"
URL="http://localhost:$PORT"
# Throwaway admin user of this test instance only: random password per run, deleted with the container.
PW="$(python3 -c 'import secrets; print(secrets.token_hex(16))')"
console user:create --admin --password="$PW" --email=compat-test@example.com compat || red "user:create"
TOKEN="$(curl -s -X POST "$URL/api/oauth/token" -H 'Content-Type: application/json' \
    -d "{\"grant_type\":\"password\",\"client_id\":\"administration\",\"username\":\"compat\",\"password\":\"$PW\",\"scopes\":\"write\"}" \
    | python3 -c 'import sys,json; print(json.load(sys.stdin)["access_token"])')" || red "Admin API token"
api() { curl -s -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -H 'Accept: application/json' "$@"; }
first_id() { python3 -c 'import sys,json; print(json.load(sys.stdin)["data"][0]["id"])'; }
TAX="$(api -X POST "$URL/api/search/tax" -d '{"limit":1}' | first_id)" || red "tax"
CUR="$(api -X POST "$URL/api/search/currency" -d '{"limit":1,"filter":[{"type":"equals","field":"isoCode","value":"EUR"}]}' | first_id)" || red "currency"
SC="$(api -X POST "$URL/api/search/sales-channel" -d '{"limit":1,"filter":[{"type":"equals","field":"typeId","value":"8a243080f92e4c719546314b577cf82b"}]}' | first_id)" || red "storefront sales channel"
PID="$(python3 -c 'import uuid; print(uuid.uuid4().hex)')"
api -X POST "$URL/api/product" -d "{\"id\":\"$PID\",\"name\":\"Compatibility test product\",\"productNumber\":\"COMPAT-1\",\"stock\":10,\"active\":true,\"taxId\":\"$TAX\",\"price\":[{\"currencyId\":\"$CUR\",\"gross\":19.99,\"net\":16.80,\"linked\":true}],\"visibilities\":[{\"salesChannelId\":\"$SC\",\"visibility\":30}]}" >>"$LOG" 2>&1
# 0.7.2: the widget is switched on per sales channel (off by default since the server-rendered widget arrived).
console system:config:set Xl0gDeliveryPromise.config.deliveryPromiseProductPage true || red "switching on the product page widget"
console cache:clear || red "cache:clear after the test product"
PDP="$URL/detail/$PID"
# /detail/<id> redirects (301) to the SEO URL of the product — follow it.
code="$(curl -s -L -o "$WORKDIR/pdp.html" -w '%{http_code}' "$PDP")"
if [ "$code" != "200" ]; then
    # Is it the plugin? Same page once more with the plugin deactivated.
    console plugin:deactivate Xl0gDeliveryPromise; console cache:clear
    ohne="$(curl -s -L -o /dev/null -w '%{http_code}' "$PDP")"
    red "product page $PDP answers $code (with the plugin deactivated: $ohne)"
fi
grep -q 'data-xl0g-delivery-promise' "$WORKDIR/pdp.html" || red "product page without the widget placeholder"

step "4. Page view beacon route (answers 204 whatever happens, also with the switch off)"
[ "$(curl -s -o /dev/null -w '%{http_code}' -X POST -d sku=COMPAT-1 "$URL/xl0g/page-view")" = "204" ] || red "/xl0g/page-view"

step "4b. Invitation to agents"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$URL/llms.txt")" = "200" ] || red "/llms.txt"
curl -s "$URL/.well-known/xl0g-delivery.json" | python3 -c 'import sys,json; d=json.load(sys.stdin); assert d.get("protocol")=="xl0g-agent-discovery"' \
    || red "/.well-known/xl0g-delivery.json"

LIVE="without xl0g access (step 6 skipped)"
if [ -n "${XL0G_TEST_API_URL:-}" ] && [ -n "${XL0G_TEST_API_KEY:-}" ]; then
    step "6. With xl0g access: delivery date in the JSON-LD and in the widget endpoint"
    # The key travels on stdin (never in a command line, never in the log). Use a test access with promise:read only.
    python3 -c 'import json,os; print(json.dumps({"null": {
        "Xl0gDeliveryPromise.config.apiBaseUrl": os.environ["XL0G_TEST_API_URL"],
        "Xl0gDeliveryPromise.config.apiKey": os.environ["XL0G_TEST_API_KEY"],
        "Xl0gDeliveryPromise.config.enableJsonLd": True}}))' \
        | api -X POST "$URL/api/_action/system-config/batch" --data-binary @- -o /dev/null -w '%{http_code}\n' >>"$LOG" 2>&1 \
        || red "saving the plugin configuration"
    console cache:clear || red "cache:clear after the configuration"
    code="$(curl -s -L -o "$WORKDIR/pdp-live.html" -w '%{http_code}' "$PDP")"
    [ "$code" = "200" ] || red "product page with xl0g access answers $code"
    python3 - "$WORKDIR/pdp-live.html" <<'PY' >>"$LOG" 2>&1 || red "product page without x-xl0g-delivery-date in the JSON-LD"
import json, re, sys
html = open(sys.argv[1], encoding="utf-8", errors="replace").read()
blocks = re.findall(r'<script type="application/ld\+json">(.*?)</script>', html, re.S)
dates = []
for b in blocks:
    try:
        d = json.loads(b)
    except ValueError:
        continue
    for item in d if isinstance(d, list) else [d]:
        if isinstance(item, dict) and item.get("x-xl0g-delivery-date"):
            dates.append(item["x-xl0g-delivery-date"])
assert dates and re.fullmatch(r"\d{4}-\d{2}-\d{2}", dates[0]), "no delivery date in %d JSON-LD blocks" % len(blocks)
print("JSON-LD delivery date:", dates[0])
# 0.7.2: handlingTime from xl0g, exact (minValue = maxValue), next to the transit time.
times = re.findall(r'"handlingTime":\{[^}]*"minValue":(\d+),"maxValue":(\d+)', html)
assert times and times[0][0] == times[0][1], "no exact handlingTime from xl0g in the JSON-LD"
print("JSON-LD handling time:", times[0][0])
PY
    curl -s "$URL/xl0g/delivery-promise?context=pdp" -H 'X-Requested-With: XMLHttpRequest' \
        | python3 -c 'import sys,json,re; d=json.load(sys.stdin); assert re.fullmatch(r"\d{4}-\d{2}-\d{2}", d.get("promised_date") or ""); print("widget endpoint:", d["promised_date"])' >>"$LOG" 2>&1 \
        || red "widget endpoint /xl0g/delivery-promise without promised_date"
    LIVE="delivery date from xl0g in JSON-LD and widget endpoint"
fi

step "7. Unit tests (PHPUnit) inside this shop — the tests use Shopware classes (mocks of the config service, structs)"
# PHPUnit into this throwaway shop only; the plugin's own namespaces are registered by a small bootstrap file.
docker run --rm -v "$VOL:/app" -w /app composer:2 sh -c "
    composer require --dev -W --no-interaction --no-scripts --ignore-platform-req='ext-*' -q phpunit/phpunit:^10.5 &&
    printf '%s\n' '<?php' \
        '\$l = require __DIR__ . \"/vendor/autoload.php\";' \
        '\$l->addPsr4(\"Xl0g\\\\DeliveryPromise\\\\\", __DIR__ . \"/custom/plugins/Xl0gDeliveryPromise/src/\");' \
        '\$l->addPsr4(\"Xl0g\\\\DeliveryPromise\\\\Tests\\\\\", __DIR__ . \"/custom/plugins/Xl0gDeliveryPromise/tests/\");' \
        > xl0g-test-bootstrap.php" >>"$LOG" 2>&1 || red "installing PHPUnit into the test shop"
docker exec -u root -w /var/www/html "$NAME-web" php vendor/bin/phpunit \
    -c custom/plugins/Xl0gDeliveryPromise/phpunit.xml.dist --bootstrap xl0g-test-bootstrap.php >>"$LOG" 2>&1 || red "PHPUnit"

echo "GREEN: Shopware $VERSION — plugin installed, theme built, product page with widget, invitation to agents, unit tests, $LIVE" | tee -a "$LOG"
