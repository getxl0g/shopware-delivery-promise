#!/usr/bin/env bash
# Checks before every push that nothing confidential is in the repository: keys, passwords, tokens, private keys,
# .env files. Optionally also a LOCAL list of forbidden words (customer, person, tenant names) — the list never lives
# in the repository: ${XL0G_PUBLIC_FORBIDDEN:-$HOME/.config/xl0g/public-forbidden-words.txt}, one word per line.
# (The former default path ~/.config/xl0g/oeffentlich-verboten.txt is still read if the new file does not exist.)
# Usage: scripts/check-public.sh  → exit 0 = clean, 1 = finding (the locations are in the output).
set -uo pipefail
cd "$(git rev-parse --show-toplevel)"
found=0
files="$(git ls-files)"
check() { # $1 = description, $2 = extended regular expression
    local hits
    hits="$(printf '%s\n' "$files" | grep -v '^scripts/check-public.sh$' | xargs grep -n -I -E -i "$2" 2>/dev/null || true)"
    if [ -n "$hits" ]; then echo "FINDING ($1):"; printf '%s\n' "$hits" | cut -c1-200; found=1; fi
}
check "private key" '-----BEGIN [A-Z ]*PRIVATE KEY-----'
check "credentials in an assignment" '(api[_-]?key|apikey|secret|passwor[dt]|token)["'"'"']?[[:space:]]*[:=][[:space:]]*["'"'"'][A-Za-z0-9_\-]{12,}'
check "GitHub/cloud token" '(ghp_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|AKIA[0-9A-Z]{16}|xox[baprs]-[A-Za-z0-9-]{10,}|sk-[A-Za-z0-9]{20,})'
check "URL with credentials" 'https?://[^/[:space:]:@]+:[^/[:space:]@]+@'
if printf '%s\n' "$files" | grep -E -q '(^|/)\.env(\.|$)'; then echo "FINDING: .env file in the repository"; found=1; fi
list="${XL0G_PUBLIC_FORBIDDEN:-$HOME/.config/xl0g/public-forbidden-words.txt}"
if [ ! -f "$list" ] && [ -z "${XL0G_PUBLIC_FORBIDDEN:-}" ] && [ -f "$HOME/.config/xl0g/oeffentlich-verboten.txt" ]; then
    list="$HOME/.config/xl0g/oeffentlich-verboten.txt"
fi
if [ -f "$list" ]; then
    while IFS= read -r word; do
        [ -z "$word" ] && continue
        case "$word" in \#*) continue ;; esac
        check "forbidden word from the local list" "$(printf '%s' "$word" | sed 's/[][\.*^$/]/\\&/g')"
    done < "$list"
else
    echo "Note: no local list of forbidden words ($list) — customer and person names not checked."
fi
[ "$found" -eq 0 ] && echo "clean: $(printf '%s\n' "$files" | wc -l | tr -d ' ') files checked"
exit "$found"
