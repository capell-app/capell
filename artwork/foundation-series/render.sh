#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

background() {
    local package="$1"
    local width="$2"
    local height="$3"
    local output="$4"

    magick "$ROOT/artwork/foundation-series/backgrounds/$package.jpg" \
        -resize "${width}x${height}^" -gravity center -extent "${width}x${height}" \
        -fill '#fffaf1' -colorize 8% "$output"
}

panel() {
    local source="$1"
    local width="$2"
    local height="$3"
    local output="$4"
    local gravity="${5:-north}"

    magick "$source" -resize "${width}x${height}^" -gravity "$gravity" \
        -extent "${width}x${height}" -bordercolor '#f9f5ed' -border 10 \
        \( +clone -background '#101d33' -shadow 34x18+0+18 \) \
        +swap -background none -layers merge +repage "$output"
}

compose() {
    local canvas="$1"
    local layer="$2"
    local x="$3"
    local y="$4"

    magick "$canvas" "$layer" -geometry "+${x}+${y}" -composite "$canvas"
}

export_jpeg() {
    local source="$1"
    local output="$2"
    local width="$3"
    local height="$4"

    mkdir -p "$(dirname "$output")"
    magick "$source" -resize "${width}x${height}!" -colorspace sRGB \
        -sampling-factor 4:4:4 -interlace Plane -quality 88 -strip "$output"
}

render_core() {
    local canvas="$WORK/core-hero.png"
    background core 2880 960 "$canvas"
    panel "$ROOT/packages/core/docs/images/screenshots/core-page-structure.png" 1510 760 "$WORK/core-main.png"
    panel "$ROOT/packages/core/docs/images/screenshots/core-settings-backed-configuration-dark.png" 1040 560 "$WORK/core-side.png"
    compose "$canvas" "$WORK/core-side.png" 120 250
    compose "$canvas" "$WORK/core-main.png" 1210 95
    export_jpeg "$canvas" "$ROOT/packages/core/docs/assets/readme/hero.jpg" 2880 960

    canvas="$WORK/core-card.png"
    background core 800 500 "$canvas"
    panel "$ROOT/packages/core/docs/images/screenshots/core-page-structure.png" 610 385 "$WORK/core-card-main.png"
    panel "$ROOT/packages/core/docs/images/screenshots/core-settings-backed-configuration-dark.png" 330 220 "$WORK/core-card-side.png"
    compose "$canvas" "$WORK/core-card-side.png" 25 235
    compose "$canvas" "$WORK/core-card-main.png" 180 45
    export_jpeg "$canvas" "$ROOT/packages/core/docs/assets/marketplace/extension-card.jpg" 800 500
}

render_admin() {
    local canvas="$WORK/admin-hero.png"
    magick "$ROOT/packages/admin/docs/images/screenshots/02-edit-page-save-as-draft.png" \
        -gravity south -chop 0x42 "$WORK/admin-form-source.png"
    background admin 2880 960 "$canvas"
    panel "$ROOT/packages/admin/docs/images/screenshots/admin-dashboard-dark.png" 900 555 "$WORK/admin-dashboard.png"
    panel "$ROOT/packages/admin/docs/images/screenshots/admin-pages-list.png" 1200 735 "$WORK/admin-table.png"
    panel "$WORK/admin-form-source.png" 1260 720 "$WORK/admin-form.png" north
    compose "$canvas" "$WORK/admin-dashboard.png" 100 280
    compose "$canvas" "$WORK/admin-table.png" 680 85
    compose "$canvas" "$WORK/admin-form.png" 1580 170
    export_jpeg "$canvas" "$ROOT/packages/admin/docs/assets/readme/hero.jpg" 2880 960

    canvas="$WORK/admin-card.png"
    background admin 800 500 "$canvas"
    panel "$ROOT/packages/admin/docs/images/screenshots/admin-pages-list.png" 545 355 "$WORK/admin-card-table.png"
    panel "$WORK/admin-form-source.png" 480 300 "$WORK/admin-card-form.png" north
    compose "$canvas" "$WORK/admin-card-table.png" 40 80
    compose "$canvas" "$WORK/admin-card-form.png" 305 145
    export_jpeg "$canvas" "$ROOT/packages/admin/docs/assets/marketplace/extension-card.jpg" 800 500
}

render_frontend() {
    local foundation="$ROOT/../capell-packages-4/packages/theme-foundation/docs/screenshots"
    local canvas="$WORK/frontend-hero.png"
    background frontend 2880 960 "$canvas"
    panel "$ROOT/packages/core/docs/images/screenshots/core-page-structure-dark.png" 760 500 "$WORK/frontend-sites.png"
    panel "$ROOT/packages/frontend/docs/images/screenshots/frontend-settings.png" 820 520 "$WORK/frontend-locale.png"
    panel "$foundation/foundation-homepage.png" 1430 790 "$WORK/frontend-foundation.png" north
    panel "$foundation/foundation-homepage-mobile.png" 350 700 "$WORK/frontend-mobile.png" north
    compose "$canvas" "$WORK/frontend-sites.png" 55 260
    compose "$canvas" "$WORK/frontend-locale.png" 580 150
    compose "$canvas" "$WORK/frontend-foundation.png" 1320 80
    compose "$canvas" "$WORK/frontend-mobile.png" 2470 180
    export_jpeg "$canvas" "$ROOT/packages/frontend/docs/assets/readme/hero.jpg" 2880 960

    canvas="$WORK/frontend-card.png"
    background frontend 800 500 "$canvas"
    panel "$ROOT/packages/core/docs/images/screenshots/core-page-structure-dark.png" 310 230 "$WORK/frontend-card-input.png"
    panel "$foundation/foundation-homepage.png" 555 390 "$WORK/frontend-card-output.png" north
    compose "$canvas" "$WORK/frontend-card-input.png" 20 155
    compose "$canvas" "$WORK/frontend-card-output.png" 245 55
    export_jpeg "$canvas" "$ROOT/packages/frontend/docs/assets/marketplace/extension-card.jpg" 800 500
}

render_installer() {
    local canvas="$WORK/installer-hero.png"
    background installer 2880 960 "$canvas"
    panel "$ROOT/packages/installer/docs/images/screenshots/install-guide-page-dark.png" 1050 650 "$WORK/installer-guide.png"
    panel "$ROOT/packages/installer/docs/images/screenshots/install-capell-page.png" 1580 790 "$WORK/installer-main.png"
    compose "$canvas" "$WORK/installer-guide.png" 180 205
    compose "$canvas" "$WORK/installer-main.png" 1120 70
    export_jpeg "$canvas" "$ROOT/packages/installer/docs/assets/readme/hero.jpg" 2880 960

    canvas="$WORK/installer-card.png"
    background installer 800 500 "$canvas"
    panel "$ROOT/packages/installer/docs/images/screenshots/install-guide-page-dark.png" 390 280 "$WORK/installer-card-guide.png"
    panel "$ROOT/packages/installer/docs/images/screenshots/install-capell-page.png" 590 385 "$WORK/installer-card-main.png"
    compose "$canvas" "$WORK/installer-card-guide.png" 25 170
    compose "$canvas" "$WORK/installer-card-main.png" 200 50
    export_jpeg "$canvas" "$ROOT/packages/installer/docs/assets/marketplace/extension-card.jpg" 800 500
}

render_marketplace() {
    local canvas="$WORK/marketplace-hero.png"
    background marketplace 2880 960 "$canvas"
    panel "$ROOT/packages/marketplace/docs/images/screenshots/marketplace-extensions-page.png" 1150 720 "$WORK/marketplace-list.png"
    panel "$ROOT/packages/marketplace/docs/images/screenshots/marketplace-extension-detail-overview.png" 1240 760 "$WORK/marketplace-detail.png" north
    panel "$ROOT/packages/marketplace/docs/images/screenshots/extension-update-advisories-dark.png" 880 540 "$WORK/marketplace-queue.png"
    compose "$canvas" "$WORK/marketplace-list.png" 80 100
    compose "$canvas" "$WORK/marketplace-detail.png" 990 65
    compose "$canvas" "$WORK/marketplace-queue.png" 1970 285
    export_jpeg "$canvas" "$ROOT/packages/marketplace/docs/assets/readme/hero.jpg" 2880 960

    canvas="$WORK/marketplace-card.png"
    background marketplace 800 500 "$canvas"
    panel "$ROOT/packages/marketplace/docs/images/screenshots/marketplace-extensions-page.png" 500 345 "$WORK/marketplace-card-list.png"
    panel "$ROOT/packages/marketplace/docs/images/screenshots/marketplace-extension-detail-overview.png" 430 350 "$WORK/marketplace-card-detail.png" north
    compose "$canvas" "$WORK/marketplace-card-list.png" 25 90
    compose "$canvas" "$WORK/marketplace-card-detail.png" 350 65
    export_jpeg "$canvas" "$ROOT/packages/marketplace/docs/assets/marketplace/extension-card.jpg" 800 500
}

render_core
render_admin
render_frontend
render_installer
render_marketplace

for package in core admin frontend installer marketplace; do
    test "$(identify -format '%wx%h' "$ROOT/packages/$package/docs/assets/readme/hero.jpg")" = '2880x960'
    test "$(identify -format '%wx%h' "$ROOT/packages/$package/docs/assets/marketplace/extension-card.jpg")" = '800x500'
done
