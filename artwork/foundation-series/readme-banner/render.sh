#!/usr/bin/env bash
# Renders the root README banner (light and dark) from banner.html.
# Requires Google Chrome and ImageMagick.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
root="$(cd "$here/../../.." && pwd)"
chrome="${CHROME:-/Applications/Google Chrome.app/Contents/MacOS/Google Chrome}"
scratch="$(mktemp -d)"
trap 'rm -rf "$scratch" "$here/capell-logo-cream.svg"' EXIT
sed 's/#001d3d/#f3ecdc/g' "$here/../capell-logo.svg" > "$here/capell-logo-cream.svg"
for theme in light dark; do
  "$chrome" --headless=new --disable-gpu --hide-scrollbars --allow-file-access-from-files \
    --force-device-scale-factor=2 --virtual-time-budget=4000 --window-size=1440,600 \
    --screenshot="$scratch/$theme.png" "file://$here/banner.html#$theme" 2>/dev/null
done
magick "$scratch/light.png" -strip -interlace Plane -quality 88 "$root/docs/images/capell-readme-hero-light.jpg"
magick "$scratch/dark.png" -strip -interlace Plane -quality 88 "$root/docs/images/capell-readme-hero.jpg"
