#!/usr/bin/env bash
set -euo pipefail
VERSION="${1:-0.10.0}"
DEST="$(dirname "$0")/../resources/takt.auto.js"
PKG="@vskstudio/takt-core@${VERSION}/dist/takt.auto.js"
MIRRORS=("https://cdn.jsdelivr.net/npm/${PKG}" "https://unpkg.com/${PKG}")
for url in "${MIRRORS[@]}"; do
  echo "fetching $url"
  if curl -fsSL --retry 3 --retry-all-errors "$url" -o "$DEST"; then
    echo "wrote $DEST ($(wc -c < "$DEST") bytes)"
    exit 0
  fi
  echo "miroir indisponible, bascule suivante…" >&2
done
echo "échec de récupération du bundle takt-core@${VERSION} sur tous les miroirs" >&2
exit 1
