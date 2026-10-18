#!/usr/bin/env bash
# Kompatibilitätstest des Plugins gegen eine echte Shopware-Version (Docker).
#
#   scripts/kompatibilitaet.sh 6.7.15.1      → Exit 0 = grün, sonst rot; Protokoll unter $ARBEIT/protokoll.txt
#
# Ablauf: Shopware der Version per Composer (Vorlage shopware/production, Kern auf die Version festgelegt) in einem
# Arbeitsverzeichnis, MariaDB daneben, Laufzeit shopware/docker-base (PHP + nginx). Das Plugin kommt als Kopie des
# eingecheckten Stands (git archive HEAD) nach custom/plugins. Geprüft wird:
#   1. plugin:install --activate läuft durch
#   2. theme:compile läuft durch (Twig-Erweiterungen und Snippets werden geladen)
#   3. Produktseite antwortet 200 und trägt den Platzhalter des Widgets (data-xl0g-delivery-promise)
#   4. /llms.txt 200 und /.well-known/xl0g-delivery.json 200 mit gültigem JSON
#   5. Unit-Tests des Plugins (PHPUnit) laufen grün
# Nicht geprüft: das Storefront-JS (braucht Node und einen Build) und der Abruf bei xl0g (kein Schlüssel im Test — ohne
# Schlüssel zeigt das Plugin bewusst nichts; geprüft wird, dass es dann nichts kaputt macht).
#
# Die Arbeitsverzeichnisse liegen unter $HOME (Colima bindet nur das Home-Verzeichnis ein) und werden am Ende gelöscht,
# außer XL0G_SW_BEHALTEN=1. Keine Zugangsdaten: die Datenbank ist eine Wegwerf-Instanz mit festen Testwerten.
set -uo pipefail

VERSION="${1:?Shopware-Version angeben, z. B. 6.7.15.1}"
case "$VERSION" in 6.6.*) PHP=8.2 ;; *) PHP=8.3 ;; esac
PHP="${XL0G_SW_PHP:-$PHP}"
REPO="$(cd "$(dirname "$0")/.." && pwd)"
ARBEIT="${XL0G_SW_ARBEIT:-$HOME/go/.sw-kompat-$VERSION}"
NAME="sw-kompat-$(echo "$VERSION" | tr . -)"
NETZ="$NAME-netz"
PORT="${XL0G_SW_PORT:-18$(printf '%03d' $((RANDOM % 900 + 100)))}"
PROTOKOLL="$ARBEIT/protokoll.txt"

raeume() {
    docker rm -f "$NAME-web" "$NAME-db" >/dev/null 2>&1
    docker network rm "$NETZ" >/dev/null 2>&1
    [ "${XL0G_SW_BEHALTEN:-0}" = "1" ] || rm -rf "$ARBEIT"
}
trap raeume EXIT
schritt() { echo "== $*" | tee -a "$PROTOKOLL"; }
rot() { echo "ROT: $*" | tee -a "$PROTOKOLL"; exit 1; }

rm -rf "$ARBEIT"; mkdir -p "$ARBEIT/shop"
: > "$PROTOKOLL"

schritt "Shopware $VERSION per Composer (PHP $PHP)"
docker run --rm -v "$ARBEIT/shop:/app" -w /app composer:2 sh -c "
    composer create-project shopware/production . --no-interaction --no-scripts --ignore-platform-reqs -q &&
    composer require -W --no-interaction --no-scripts --ignore-platform-reqs -q \
        shopware/core:$VERSION shopware/storefront:$VERSION shopware/administration:$VERSION" >>"$PROTOKOLL" 2>&1 \
    || rot "Composer konnte Shopware $VERSION nicht auflösen"

schritt "Plugin aus dem eingecheckten Stand"
mkdir -p "$ARBEIT/shop/custom/plugins/Xl0gDeliveryPromise"
git -C "$REPO" archive HEAD | tar -x -C "$ARBEIT/shop/custom/plugins/Xl0gDeliveryPromise"

schritt "Datenbank und Laufzeit"
docker network create "$NETZ" >/dev/null
docker run -d --name "$NAME-db" --network "$NETZ" -e MARIADB_ROOT_PASSWORD=test -e MARIADB_DATABASE=shopware mariadb:11 >/dev/null
for i in $(seq 1 60); do docker exec "$NAME-db" mariadb -uroot -ptest -e 'select 1' >/dev/null 2>&1 && break; sleep 2; done
docker run -d --name "$NAME-web" --network "$NETZ" -p "127.0.0.1:$PORT:8000" \
    -v "$ARBEIT/shop:/var/www/html" \
    -e DATABASE_URL="mysql://root:test@$NAME-db:3306/shopware" -e APP_URL="http://localhost:$PORT" \
    -e APP_ENV=prod -e APP_SECRET=testgeheimnis-nur-fuer-den-kompatibilitaetstest -e INSTANCE_ID=kompat \
    "shopware/docker-base:$PHP-nginx" >/dev/null || rot "Laufzeit shopware/docker-base:$PHP-nginx startet nicht"
konsole() { docker exec -u root -w /var/www/html "$NAME-web" php -d memory_limit=2G bin/console "$@" >>"$PROTOKOLL" 2>&1; }

schritt "Shopware installieren"
docker exec -u root -w /var/www/html "$NAME-web" sh -c 'composer run-script post-install-cmd >/dev/null 2>&1 || true; chown -R www-data /var/www/html' >>"$PROTOKOLL" 2>&1
konsole system:install --basic-setup --create-database --force || rot "system:install"

schritt "1. Plugin installieren und aktivieren"
konsole plugin:refresh || rot "plugin:refresh"
konsole plugin:install --activate Xl0gDeliveryPromise || rot "plugin:install"
schritt "2. Theme bauen"
konsole theme:compile || rot "theme:compile"
konsole cache:clear || rot "cache:clear"
docker exec -u root "$NAME-web" chown -R www-data /var/www/html >/dev/null 2>&1

schritt "3. Produktseite"
URL="http://localhost:$PORT"
PDP="$(curl -s "$URL/" | grep -oE 'href="[^"]+/detail/[0-9a-f]{32}"' | head -1 | sed -E 's/href="([^"]+)"/\1/')"
[ -n "$PDP" ] || PDP="$(curl -s "$URL/" | grep -oE "href=\"$URL/[^\"]+/[A-Za-z0-9.-]+\"" | head -1 | sed -E 's/href="([^"]+)"/\1/')"
[ -n "$PDP" ] || rot "keine Produktseite auf der Startseite gefunden"
code="$(curl -s -o "$ARBEIT/pdp.html" -w '%{http_code}' "$PDP")"
[ "$code" = "200" ] || rot "Produktseite $PDP antwortet $code"
grep -q 'data-xl0g-delivery-promise' "$ARBEIT/pdp.html" || rot "Produktseite ohne Platzhalter des Widgets"

schritt "4. Einladung an Agenten"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$URL/llms.txt")" = "200" ] || rot "/llms.txt"
curl -s "$URL/.well-known/xl0g-delivery.json" | python3 -c 'import sys,json; d=json.load(sys.stdin); assert d.get("protocol")=="xl0g-agent-discovery"' \
    || rot "/.well-known/xl0g-delivery.json"

schritt "5. Unit-Tests (ohne Shopware-Kern, wie in docs/DEVELOPMENT.md)"
mkdir -p "$ARBEIT/unit" && cp -R "$REPO/src" "$REPO/tests" "$REPO/phpunit.xml.dist" "$REPO/composer.json" "$ARBEIT/unit/"
python3 -c "import json,sys; p=sys.argv[1]; d=json.load(open(p)); d['require']={}; d.setdefault('autoload-dev',{})['psr-4']={'Xl0g\\\\DeliveryPromise\\\\Tests\\\\':'tests/'}; json.dump(d,open(p,'w'),indent=2)" "$ARBEIT/unit/composer.json"
docker run --rm -v "$ARBEIT/unit:/app" -w /app composer:2 sh -c 'composer install --no-interaction -q && vendor/bin/phpunit' >>"$PROTOKOLL" 2>&1 || rot "PHPUnit"

echo "GRÜN: Shopware $VERSION — Plugin installiert, Theme gebaut, Produktseite mit Widget, Einladung an Agenten, Unit-Tests" | tee -a "$PROTOKOLL"
