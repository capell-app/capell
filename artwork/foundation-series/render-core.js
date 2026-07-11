const fs = require('fs')
const os = require('os')
const path = require('path')
const { execFileSync } = require('child_process')

const root = path.resolve(__dirname, '../..')
const temporaryRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'capell-core-art-'))
const palette = {
    paper: '#f6efe2',
    ink: '#10233f',
    blue: '#9bc8df',
    green: '#9bcdb0',
    amber: '#e4ae4f',
    white: '#fffaf0',
}
const inputs = {
    environment: 'artwork/foundation-series/backgrounds/core-engraving.jpg',
    logo: 'artwork/foundation-series/capell-logo.svg',
    factualPageStructureReference:
        'packages/core/docs/images/screenshots/core-page-structure.png',
    factualSettingsReference:
        'packages/core/docs/images/screenshots/core-settings-backed-configuration-dark.png',
}
const outputs = {
    hero: 'packages/core/docs/assets/readme/hero.jpg',
    card: 'packages/core/docs/assets/marketplace/extension-card.jpg',
}

function data(relativePath) {
    const ext = path.extname(relativePath).slice(1)
    return `data:image/${ext === 'svg' ? 'svg+xml' : ext};base64,${fs.readFileSync(path.join(root, relativePath)).toString('base64')}`
}

const environmentRaster = path.join(temporaryRoot, 'environment.jpg')
execFileSync('magick', [
    path.join(root, inputs.environment),
    '-resize',
    '3200x1400^',
    '-gravity',
    'center',
    '-extent',
    '3200x1400',
    '-quality',
    '84',
    environmentRaster,
])
const environment = `data:image/jpeg;base64,${fs.readFileSync(environmentRaster).toString('base64')}`
const logo = data(inputs.logo)

function defs(id) {
    return `<defs><filter id="${id}-shadow" x="-20%" y="-20%" width="140%" height="150%"><feDropShadow dx="0" dy="10" stdDeviation="10" flood-color="${palette.ink}" flood-opacity=".18"/></filter><linearGradient id="${id}-veil"><stop stop-color="${palette.paper}" stop-opacity=".96"/><stop offset=".55" stop-color="${palette.paper}" stop-opacity=".42"/><stop offset="1" stop-color="${palette.paper}" stop-opacity=".08"/></linearGradient></defs>`
}
function bg(w, h, id) {
    return `<rect width="${w}" height="${h}" fill="${palette.paper}"/><image href="${environment}" width="${w}" height="${h}" preserveAspectRatio="xMidYMid slice"/><rect width="${w}" height="${h}" fill="url(#${id}-veil)" opacity=".3"/>`
}
function text(
    x,
    y,
    value,
    size,
    weight = 600,
    anchor = 'start',
    fill = palette.ink,
) {
    return `<text x="${x}" y="${y}" fill="${fill}" font-family="Avenir Next, Helvetica, Arial, sans-serif" font-size="${size}" font-weight="${weight}" text-anchor="${anchor}" letter-spacing=".7">${value}</text>`
}
function lockup(x, y, logoWidth, coreX, coreSize) {
    return `<g aria-label="Capell Core"><image href="${logo}" x="${x}" y="${y}" width="${logoWidth}" preserveAspectRatio="xMinYMin meet"/><text x="${coreX}" y="${y + coreSize * 0.78}" fill="${palette.ink}" font-family="Cormorant Garamond, Didot, Georgia, serif" font-size="${coreSize}" font-weight="300" letter-spacing="${coreSize * 0.035}">CORE</text></g>`
}
function pill(x, y, label, color, compact = false) {
    const h = compact ? 28 : 42
    const r = h / 2
    const w = label.length * (compact ? 8 : 12) + (compact ? 28 : 42)
    return `<g><rect x="${x}" y="${y}" width="${w}" height="${h}" rx="${r}" fill="${color}" stroke="${palette.ink}" stroke-width="2"/>${text(x + w / 2, y + h * 0.68, label, compact ? 12 : 18, 800, 'middle')}</g>`
}
function plane(x, y, w, label, sub, color, compact = false) {
    const h = compact ? 40 : 70
    return `<g><rect x="${x}" y="${y}" width="${w}" height="${h}" rx="${compact ? 8 : 14}" fill="${palette.white}" fill-opacity=".94" stroke="${palette.ink}" stroke-width="${compact ? 2 : 4}"/><rect x="${x}" y="${y}" width="${compact ? 10 : 16}" height="${h}" rx="${compact ? 5 : 8}" fill="${color}"/>${text(x + (compact ? 24 : 34), y + (compact ? 18 : 29), label, compact ? 12 : 20, 800)}${text(x + (compact ? 24 : 34), y + (compact ? 33 : 53), sub, compact ? 10 : 14, 500)}</g>`
}
function spine(x, y, w, h, compact = false) {
    const pad = compact ? 14 : 28
    const planeW = w - pad * 2
    const step = compact ? 50 : 86
    return `<g filter="url(#${compact ? 'card' : 'hero'}-shadow)"><rect x="${x}" y="${y}" width="${w}" height="${h}" rx="${compact ? 10 : 20}" fill="${palette.ink}"/><rect x="${x + pad - 4}" y="${y + 18}" width="${w - (pad - 4) * 2}" height="${h - 36}" rx="${compact ? 6 : 12}" fill="${palette.paper}" fill-opacity=".16"/>${plane(x + pad, y + (compact ? 26 : 42), planeW, 'SITE + LANGUAGE', 'context', palette.blue, compact)}${plane(x + pad, y + (compact ? 26 : 42) + step, planeW, 'PAGE + URL', 'content + addressing', palette.green, compact)}${plane(x + pad, y + (compact ? 26 : 42) + step * 2, planeW, 'SETTINGS + THEME', 'configuration + presentation', palette.amber, compact)}</g>`
}
function socket(x, y, label, color, compact = false) {
    const r = compact ? 9 : 14
    return `<g><circle cx="${x}" cy="${y}" r="${r + 5}" fill="${palette.paper}" stroke="${palette.ink}" stroke-width="3"/><circle cx="${x}" cy="${y}" r="${r}" fill="${color}" stroke="${palette.ink}" stroke-width="2"/>${text(x + (compact ? 16 : 24), y + 6, label, compact ? 11 : 16, 700)}</g>`
}
function site(x, y, w, h, color, title, compact = false) {
    return `<g filter="url(#${compact ? 'card' : 'hero'}-shadow)"><path d="M${x} ${y + 22}L${x + 18} ${y}H${x + w - 18}L${x + w} ${y + 22}V${y + h}H${x}Z" fill="${palette.white}" fill-opacity=".95" stroke="${palette.ink}" stroke-width="${compact ? 2 : 4}"/><path d="M${x + 10} ${y + 28}H${x + w - 10}" stroke="${color}" stroke-width="${compact ? 5 : 10}"/>${text(x + 16, y + (compact ? 54 : 78), title, compact ? 11 : 18, 800)}<rect x="${x + 16}" y="${y + (compact ? 66 : 96)}" width="${w - 32}" height="${compact ? 12 : 22}" rx="6" fill="${color}" opacity=".7"/><rect x="${x + 16}" y="${y + (compact ? 86 : 132)}" width="${w * 0.58}" height="${compact ? 8 : 14}" rx="4" fill="${palette.ink}" opacity=".28"/></g>`
}
function journey(x, y, labels, compact = false) {
    const gap = compact ? 108 : 190
    return `<g>${labels.map((label, i) => `${pill(x + i * gap, y, `${i + 1}  ${label}`, [palette.amber, palette.blue, palette.green, palette.amber][i], compact)}${i < labels.length - 1 ? `<path d="M${x + i * gap + (compact ? 82 : 130)} ${y + (compact ? 14 : 21)}H${x + (i + 1) * gap - (compact ? 26 : 38)}" stroke="${palette.ink}" stroke-width="${compact ? 2 : 4}"/><path d="m${x + (i + 1) * gap - (compact ? 34 : 48)} ${y + (compact ? 7 : 13)} 12 ${compact ? 7 : 8} -12 ${compact ? 7 : 8}" fill="none" stroke="${palette.ink}" stroke-width="${compact ? 2 : 4}"/>` : ''}`).join('')}</g>`
}
function heroSvg() {
    return `<svg xmlns="http://www.w3.org/2000/svg" width="2880" height="960" viewBox="0 0 2880 960"><title>Capell Core — The structure beneath every site</title>${defs('hero')}${bg(2880, 960, 'hero')}<rect x="72" y="58" width="2736" height="844" rx="26" fill="${palette.paper}" fill-opacity=".76" stroke="${palette.ink}" stroke-width="4"/>${lockup(124, 94, 300, 448, 112)}${text(126, 284, 'The structure beneath every site', 30, 600)}${text(126, 322, 'Installed inside Laravel. Owned by your application.', 18, 500)}${text(1120, 102, 'LARAVEL APPLICATION', 18, 800)}<rect x="1080" y="128" width="1630" height="650" rx="20" fill="${palette.paper}" fill-opacity=".72" stroke="${palette.ink}" stroke-width="4"/>${text(1120, 174, 'CAPELL CORE', 20, 800)}${spine(1510, 212, 520, 438)}${socket(1350, 270, 'extension socket', palette.amber)}${socket(1350, 436, 'extension socket', palette.blue)}${socket(2130, 270, 'extension socket', palette.green)}${socket(2130, 606, 'extension socket', palette.amber)}<path d="M2100 436H2290" stroke="${palette.ink}" stroke-width="6"/><rect x="2290" y="354" width="260" height="164" rx="18" fill="${palette.white}" stroke="${palette.ink}" stroke-width="4"/>${text(2420, 405, 'ADMIN', 19, 800, 'middle')}${text(2420, 438, 'controlled interface', 14, 500, 'middle')}<path d="M1770 650V734H1330" fill="none" stroke="${palette.green}" stroke-width="8"/><circle cx="1330" cy="734" r="12" fill="${palette.green}" stroke="${palette.ink}" stroke-width="3"/>${text(1130, 730, 'APPLICATION-OWNED FRONTEND', 16, 800)}${site(1120, 778, 260, 82, palette.green, 'Site A')}${site(1410, 778, 260, 82, palette.blue, 'Site B')}${site(1700, 778, 260, 82, palette.amber, 'Site C')}${text(2320, 710, 'COMPOSER PACKAGE', 15, 800)}<rect x="2320" y="726" width="210" height="52" rx="10" fill="${palette.blue}" stroke="${palette.ink}" stroke-width="3"/>${text(2425, 759, 'optional extension', 14, 700, 'middle')}${journey(126, 430, ['Define', 'Connect', 'Resolve', 'Extend'])}</svg>`
}
function cardSvg() {
    return `<svg xmlns="http://www.w3.org/2000/svg" width="800" height="500" viewBox="0 0 800 500"><title>Capell Core structural spine</title>${defs('card')}${bg(800, 500, 'card')}<rect x="16" y="16" width="768" height="468" rx="14" fill="${palette.paper}" fill-opacity=".78" stroke="${palette.ink}" stroke-width="3"/>${lockup(34, 30, 156, 198, 44)}${text(36, 112, 'The structure beneath every site', 13, 600)}${text(36, 148, 'LARAVEL APPLICATION', 12, 800)}<rect x="30" y="160" width="740" height="290" rx="12" fill="${palette.paper}" fill-opacity=".65" stroke="${palette.ink}" stroke-width="2"/>${text(48, 188, 'CAPELL CORE', 12, 800)}${spine(58, 210, 238, 202, true)}${socket(316, 244, 'EXTEND', palette.amber, true)}<path d="M316 310H382" stroke="${palette.ink}" stroke-width="3"/><rect x="382" y="274" width="112" height="66" rx="8" fill="${palette.white}" stroke="${palette.ink}" stroke-width="2"/>${text(438, 302, 'ADMIN', 11, 800, 'middle')}${text(438, 321, 'interface', 9, 500, 'middle')}<path d="M178 416V438H520" stroke="${palette.green}" stroke-width="4"/><text x="330" y="438" fill="${palette.ink}" font-family="Avenir Next, Helvetica, Arial, sans-serif" font-size="10" font-weight="800" text-anchor="middle">APPLICATION-OWNED FRONTEND</text>${site(520, 198, 102, 84, palette.green, 'Site A', true)}${site(642, 198, 102, 84, palette.blue, 'Site B', true)}${text(526, 314, 'COMPOSER', 10, 800)}<rect x="526" y="322" width="112" height="26" rx="6" fill="${palette.blue}" stroke="${palette.ink}" stroke-width="2"/>${text(582, 340, 'package socket', 9, 700, 'middle')}${journey(54, 462, ['Define', 'Reuse', 'Extend'], true)}</svg>`
}
function render(svg, name, width, height, relativeOutput) {
    const source = path.join(temporaryRoot, `${name}.svg`)
    const png = path.join(temporaryRoot, `${name}.png`)
    fs.writeFileSync(source, svg)
    execFileSync('rsvg-convert', [
        '--width',
        String(width),
        '--height',
        String(height),
        '--output',
        png,
        source,
    ])
    execFileSync('magick', [
        png,
        '-colorspace',
        'sRGB',
        '-sampling-factor',
        '4:4:4',
        '-interlace',
        'Plane',
        '-quality',
        '88',
        '-strip',
        path.join(root, relativeOutput),
    ])
}

render(heroSvg(), 'hero', 2880, 960, outputs.hero)
render(cardSvg(), 'card', 800, 500, outputs.card)
fs.rmSync(temporaryRoot, { recursive: true, force: true })
console.log('Rendered Capell Core structural spine artwork.')
