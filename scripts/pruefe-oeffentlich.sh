#!/usr/bin/env bash
# Prüft vor jedem Push, dass nichts Vertrauliches im Repository steht: Schlüssel, Passwörter, Tokens, private Schlüssel,
# .env-Dateien. Optional zusätzlich eine LOKALE Liste verbotener Wörter (Kunden-, Personen-, Mandantennamen) — die Liste
# liegt nie im Repository: ${XL0G_OEFFENTLICH_VERBOTEN:-$HOME/.config/xl0g/oeffentlich-verboten.txt}, ein Wort je Zeile.
# Aufruf: scripts/pruefe-oeffentlich.sh  → Exit 0 = sauber, 1 = Fund (die Fundstellen stehen in der Ausgabe).
set -uo pipefail
cd "$(git rev-parse --show-toplevel)"
fund=0
dateien="$(git ls-files)"
pruef() { # $1 = Beschreibung, $2 = erweiterter regulärer Ausdruck
    local treffer
    treffer="$(printf '%s\n' "$dateien" | grep -v '^scripts/pruefe-oeffentlich.sh$' | xargs grep -n -I -E -i "$2" 2>/dev/null || true)"
    if [ -n "$treffer" ]; then echo "FUND ($1):"; printf '%s\n' "$treffer" | cut -c1-200; fund=1; fi
}
pruef "privater Schlüssel" '-----BEGIN [A-Z ]*PRIVATE KEY-----'
pruef "Zugangsdaten in Zuweisung" '(api[_-]?key|apikey|secret|passwor[dt]|token)["'"'"']?[[:space:]]*[:=][[:space:]]*["'"'"'][A-Za-z0-9_\-]{12,}'
pruef "GitHub-/Cloud-Token" '(ghp_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|AKIA[0-9A-Z]{16}|xox[baprs]-[A-Za-z0-9-]{10,}|sk-[A-Za-z0-9]{20,})'
pruef "URL mit Zugangsdaten" 'https?://[^/[:space:]:@]+:[^/[:space:]@]+@'
if printf '%s\n' "$dateien" | grep -E -q '(^|/)\.env(\.|$)'; then echo "FUND: .env-Datei im Repository"; fund=1; fi
liste="${XL0G_OEFFENTLICH_VERBOTEN:-$HOME/.config/xl0g/oeffentlich-verboten.txt}"
if [ -f "$liste" ]; then
    while IFS= read -r wort; do
        [ -z "$wort" ] && continue
        case "$wort" in \#*) continue ;; esac
        pruef "verbotenes Wort aus der lokalen Liste" "$(printf '%s' "$wort" | sed 's/[][\.*^$/]/\\&/g')"
    done < "$liste"
else
    echo "Hinweis: keine lokale Liste verbotener Wörter ($liste) — Kunden- und Personennamen nicht geprüft."
fi
[ "$fund" -eq 0 ] && echo "sauber: $(printf '%s\n' "$dateien" | wc -l | tr -d ' ') Dateien geprüft"
exit "$fund"
