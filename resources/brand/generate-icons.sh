#!/usr/bin/env bash
#
# Regenerate all icons and logo images from public/img/logo-brand.svg (the
# white mark shown in the header). Run after changing the logo:
#
#   resources/brand/generate-icons.sh
#
# Needs Google Chrome (to render SVG and text exactly as browsers do) and
# ImageMagick (to downsize the small favicons and build favicon.ico).

set -euo pipefail

cd "$(dirname "$0")/../.."

BLUE='#0078D7'
NAVY='#104A7D'
GREY='#4F5D69'
CHROME=$(command -v google-chrome || command -v google-chrome-stable || command -v chromium)

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

source_svg=public/img/logo-brand.svg

# The mark in a given colour, as a data URI.
mark() {
    local svg
    svg=$(sed "s/#FFFFFF/$1/g" "$source_svg")
    echo "data:image/svg+xml;base64,$(printf '%s' "$svg" | base64 -w0)"
}

# render OUTPUT WIDTH HEIGHT BODY_HTML [BACKGROUND]
# Renders HTML at exactly WIDTHxHEIGHT; without a background it is transparent.
render() {
    local out=$1 width=$2 height=$3 body=$4 background=${5:-transparent}
    cat > "$work/page.html" <<EOF
<!doctype html><html><head><style>
html, body { margin: 0; width: ${width}px; height: ${height}px; overflow: hidden; background: $background; }
body { display: flex; flex-direction: column; align-items: center; justify-content: center; font-family: Lato, sans-serif; }
</style></head><body>$body</body></html>
EOF
    "$CHROME" --headless=new --disable-gpu --no-sandbox --hide-scrollbars --force-device-scale-factor=1 \
        --default-background-color=00000000 --window-size="$width,$height" \
        --screenshot="$work/shot.png" "file://$work/page.html" >/dev/null 2>&1
    # Headless Chrome may pad the viewport; keep exactly the requested area.
    convert "$work/shot.png" -crop "${width}x${height}+0+0" +repage "$out"
}

# icon OUTPUT SIZE COLOUR SCALE [BACKGROUND]: the mark alone, centred.
icon() {
    local size=$2 inner
    inner=$(awk -v s="$2" -v f="$4" 'BEGIN { printf "%d", s * f }')
    render "$1" "$size" "$size" "<img src=\"$(mark "$3")\" width=\"$inner\" height=\"$inner\">" "${5:-transparent}"
}

# wordmark OUTPUT SIZE BACKGROUND: mark with "Tallport" and the tagline.
wordmark() {
    local size=$2 m=$(( $2 * 36 / 100 )) name=$(( $2 * 17 / 100 )) tag=$(( $2 * 45 / 1000 ))
    render "$1" "$size" "$size" "<img src=\"$(mark "$BLUE")\" width=\"$m\" height=\"$m\">
<div style=\"font-weight: 700; font-size: ${name}px; color: $NAVY; line-height: 1.1; margin-top: $(( size * 5 / 100 ))px\">Tallport</div>
<div style=\"font-size: ${tag}px; color: $GREY; letter-spacing: 0.02em\">Help Desk &amp; Shared Mailbox</div>" "$3"
}

icon public/img/logo-icon-150.png 150 "$BLUE" 1
icon public/img/logo-icon-white-300.png 300 '#FFFFFF' 0.7 "$BLUE"
icon public/apple-touch-icon.png 180 "$BLUE" 0.78 '#FFFFFF'
icon public/android-chrome-192x192.png 192 "$BLUE" 1
icon public/android-chrome-256x256.png 256 "$BLUE" 1
icon public/mstile-150x150.png 270 "$BLUE" 0.5
wordmark public/img/logo-300.png 300 '#FFFFFF'
wordmark public/img/logo-600.png 600 transparent

# Favicons: render large, then downsize.
icon "$work/favicon-256.png" 256 "$BLUE" 1
convert "$work/favicon-256.png" -resize 16x16 public/favicon.png
convert "$work/favicon-256.png" -resize 16x16 public/favicon.gif
convert "$work/favicon-256.png" -define icon:auto-resize=48,32,16 public/favicon.ico

# Safari pinned tab: a single-colour SVG (Safari applies the colour).
sed 's/#FFFFFF/#000000/g; s/ role="img" aria-label="[^"]*"//' "$source_svg" > public/safari-pinned-tab.svg

# Opaque images stay opaque (apple-touch-icon, logo-300, logo-icon-white-300).
for opaque in public/apple-touch-icon.png public/img/logo-300.png public/img/logo-icon-white-300.png; do
    convert "$opaque" -background white -alpha remove -alpha off "$opaque"
done

echo "Icons regenerated from $source_svg"
