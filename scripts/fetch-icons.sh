#!/bin/sh
# Re-download the Material Symbols Rounded subset after editing resources/fonts/material-icons.txt.
# Only the listed icons are bundled, which keeps the font small and available offline.
set -e
cd "$(dirname "$0")/.."
ICONS=$(tr ',' '\n' < resources/fonts/material-icons.txt | sed '/^$/d' | sort -u | paste -sd, -)
printf '%s\n' "$ICONS" > resources/fonts/material-icons.txt
UA="Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36"
CSS=$(curl -fsS -A "$UA" "https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&icon_names=$ICONS&display=block")
URL=$(printf '%s' "$CSS" | grep -o 'https://[^)]*' | head -1)
curl -fsS -o resources/fonts/material-symbols-rounded.woff2 "$URL"
echo "Saved $(wc -c < resources/fonts/material-symbols-rounded.woff2) bytes"
