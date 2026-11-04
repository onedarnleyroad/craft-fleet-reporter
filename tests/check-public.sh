#!/usr/bin/env bash
# Fails if tracked files mention any host outside the allowlist, so the repo
# never leaks where Fleet runs or which clients use it. Run before every push.
set -euo pipefail
cd "$(dirname "$0")/.."

allowed='^(www\.example\.(com|org)|example\.(com|org)|localhost|127\.0\.0\.1|host\.docker\.internal|www\.onedarnleyroad\.com|github\.com|json-schema\.org|craftcms\.com)$'

found=$(git ls-files -z | xargs -0 grep -hoE 'https?://[A-Za-z0-9.-]+' 2>/dev/null \
  | sed -E 's#https?://##' | sort -u | grep -vE "$allowed" || true)

if [ -n "$found" ]; then
  echo "Hosts not on the public allowlist:"
  echo "$found" | sed 's/^/  /'
  exit 1
fi
echo "ok: no unexpected hosts"
