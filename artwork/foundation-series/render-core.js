const fs = require('fs')
const os = require('os')
const path = require('path')
const { execFileSync } = require('child_process')

const root = path.resolve(__dirname, '../..')
const temporaryRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'capell-core-art-'))
const environmentRasterPath = path.join(temporaryRoot, 'core-engraving.jpg')

const palette = {
    paper: '#f5eddf',
    paperLight: '#fffaf0',
    navy: '#10233f',
    navyDeep: '#07172d',
    amber: '#e6af50',
    blue: '#9bc8df',
    emerald: '#94c9ae',
    coral: '#df9f91',
    white: '#ffffff',
}

const inputs = {
    environment: 'artwork/foundation-series/backgrounds/core-engraving.jpg',
    logo: 'artwork/foundation-series/capell-logo.svg',
    pageStructure:
        'packages/core/docs/images/screenshots/core-page-structure.png',
    settings:
        'packages/core/docs/images/screenshots/core-settings-backed-configuration-dark.png',
}

const outputs = {
    hero: 'packages/core/docs/assets/readme/hero.jpg',
    card: 'packages/core/docs/assets/marketplace/extension-card.jpg',
}

function assetData(relativePath) {
    const extension = path.extname(relativePath).slice(1)
    const mimeType =
        extension === 'svg' ? 'image/svg+xml' : `image/${extension}`

    return `data:${mimeType};base64,${fs
        .readFileSync(path.join(root, relativePath))
        .toString('base64')}`
}

execFileSync('magick', [
    path.join(root, inputs.environment),
    '-resize',
    '3200x1400^',
    '-gravity',
    'center',
    '-extent',
    '3200x1400',
    '-quality',
    '86',
    environmentRasterPath,
])

const environmentData = `data:image/jpeg;base64,${fs
    .readFileSync(environmentRasterPath)
    .toString('base64')}`

function definitions(prefix) {
    return `<defs>
    <filter id="${prefix}-shadow" x="-30%" y="-30%" width="160%" height="180%">
      <feDropShadow dx="0" dy="16" stdDeviation="16" flood-color="${palette.navyDeep}" flood-opacity=".2"/>
    </filter>
    <linearGradient id="${prefix}-wash" x1="0" x2="1">
      <stop offset="0" stop-color="${palette.paper}" stop-opacity=".98"/>
      <stop offset=".38" stop-color="${palette.paper}" stop-opacity=".58"/>
      <stop offset=".72" stop-color="${palette.paper}" stop-opacity=".08"/>
    </linearGradient>
  </defs>`
}

function environment(width, height, prefix, position = 'xMidYMid slice') {
    return `<rect width="${width}" height="${height}" fill="${palette.paper}"/>
  <image href="${environmentData}" width="${width}" height="${height}" preserveAspectRatio="${position}"/>
  <rect width="${width}" height="${height}" fill="url(#${prefix}-wash)" opacity=".18"/>`
}

function lockup(x, y, logoWidth, coreX, coreSize) {
    return `<g aria-label="Capell Core">
    <image href="${assetData(inputs.logo)}" x="${x}" y="${y}" width="${logoWidth}" preserveAspectRatio="xMinYMin meet"/>
    <text x="${coreX}" y="${y + coreSize * 0.77}" fill="${palette.navy}" font-family="Cormorant Garamond, Didot, Georgia, serif" font-size="${coreSize}" font-weight="300" letter-spacing="${coreSize * 0.035}">CORE</text>
  </g>`
}

function station(index, label, x, y, color, compact = false) {
    const size = compact ? 28 : 40
    const fontSize = compact ? 13 : 17

    return `<g transform="translate(${x} ${y})">
    <circle cx="${size / 2}" cy="${size / 2}" r="${size / 2}" fill="${color}" stroke="${palette.navy}" stroke-width="2.5"/>
    <text x="${size / 2}" y="${size * 0.68}" text-anchor="middle" fill="${palette.navy}" font-family="Avenir Next, Helvetica, Arial, sans-serif" font-size="${fontSize}" font-weight="800">${index}</text>
    <text x="${size + 12}" y="${size * 0.67}" fill="${palette.navy}" font-family="Avenir Next, Helvetica, Arial, sans-serif" font-size="${fontSize}" font-weight="700" letter-spacing="1.2">${label.toUpperCase()}</text>
  </g>`
}

function wireframePageStructure(x, y, width, height, prefix = 'page') {
    const rowWidth = width - 92
    const rows = [0, 18, 38, 18, 38, 58]
        .map((indent, index) => {
            const rowY = 70 + index * 43
            const color = [
                palette.blue,
                palette.emerald,
                palette.amber,
                palette.coral,
            ][index % 4]
            return `<g transform="translate(${54 + indent} ${rowY})">
        <circle cx="10" cy="13" r="7" fill="${color}" stroke="${palette.navy}" stroke-width="2"/>
        <rect x="28" y="4" width="${Math.max(80, rowWidth - indent - index * 24)}" height="18" rx="9" fill="${color}" opacity=".82"/>
        <rect x="${rowWidth - 12}" y="7" width="28" height="12" rx="6" fill="none" stroke="${palette.navy}" stroke-width="2"/>
      </g>`
        })
        .join('')

    return `<g transform="translate(${x} ${y})" filter="url(#${prefix}-shadow)">
    <rect width="${width}" height="${height}" rx="16" fill="${palette.paperLight}" fill-opacity=".94" stroke="${palette.navy}" stroke-width="5"/>
    <rect width="42" height="${height}" rx="12" fill="${palette.navy}"/>
    <circle cx="21" cy="25" r="7" fill="${palette.amber}"/>
    <circle cx="21" cy="52" r="7" fill="${palette.blue}"/>
    <circle cx="21" cy="79" r="7" fill="${palette.emerald}"/>
    <rect x="65" y="25" width="${width * 0.32}" height="16" rx="8" fill="${palette.navy}" opacity=".86"/>
    <rect x="${width - 104}" y="20" width="70" height="26" rx="13" fill="${palette.amber}" stroke="${palette.navy}" stroke-width="2"/>
    <path d="M70 90V${height - 38}M90 133V${height - 38}M110 176V${height - 38}" stroke="${palette.navy}" stroke-width="2" opacity=".3"/>
    ${rows}
  </g>`
}

function wireframeSettings(x, y, width, height, prefix = 'settings') {
    const controlRows = [0, 1, 2, 3]
        .map((index) => {
            const rowY = 62 + index * 54
            const color = [
                palette.emerald,
                palette.blue,
                palette.coral,
                palette.amber,
            ][index]
            return `<g transform="translate(118 ${rowY})">
        <rect width="${width - 150}" height="38" rx="8" fill="none" stroke="${palette.navy}" stroke-width="2" opacity=".55"/>
        <rect x="14" y="12" width="${90 + index * 22}" height="14" rx="7" fill="${color}"/>
        <circle cx="${width - 176}" cy="19" r="8" fill="${color}" stroke="${palette.navy}" stroke-width="2"/>
      </g>`
        })
        .join('')

    return `<g transform="translate(${x} ${y})" filter="url(#${prefix}-shadow)">
    <rect width="${width}" height="${height}" rx="16" fill="${palette.paperLight}" fill-opacity=".94" stroke="${palette.navy}" stroke-width="5"/>
    <rect width="92" height="${height}" rx="12" fill="${palette.navy}"/>
    <rect x="18" y="25" width="52" height="10" rx="5" fill="${palette.blue}"/>
    <rect x="18" y="55" width="42" height="10" rx="5" fill="${palette.emerald}"/>
    <rect x="18" y="85" width="58" height="10" rx="5" fill="${palette.coral}"/>
    <rect x="118" y="24" width="${width * 0.34}" height="16" rx="8" fill="${palette.navy}" opacity=".86"/>
    ${controlRows}
  </g>`
}

function layerRail(x, y, width) {
    const layers = [
        'Site',
        'Language',
        'Page',
        'URL',
        'Settings',
        'Theme',
        'Extension',
    ]
    return `<g transform="translate(${x} ${y})">
    <path d="M0 0H${width}" stroke="${palette.navy}" stroke-width="5"/>
    ${layers
        .map((label, index) => {
            const layerX = (width / (layers.length - 1)) * index
            const color = [
                palette.amber,
                palette.blue,
                palette.emerald,
                palette.coral,
            ][index % 4]
            return `<g transform="translate(${layerX})">
        <circle r="9" fill="${color}" stroke="${palette.navy}" stroke-width="3"/>
        <text x="0" y="35" text-anchor="middle" fill="${palette.navy}" font-family="Avenir Next, Helvetica, Arial, sans-serif" font-size="15" font-weight="800" letter-spacing=".8">${label.toUpperCase()}</text>
      </g>`
        })
        .join('')}
  </g>`
}

function heroSvg() {
    return `<svg xmlns="http://www.w3.org/2000/svg" width="2880" height="960" viewBox="0 0 2880 960">
  <title>Capell Core — The structure beneath every site</title>
  ${definitions('hero')}
  ${environment(2880, 960, 'hero')}
  <rect x="72" y="62" width="935" height="820" rx="24" fill="${palette.paper}" fill-opacity=".88" stroke="${palette.navy}" stroke-width="3"/>
  ${lockup(112, 104, 424, 554, 118)}
  <text x="116" y="338" fill="${palette.navy}" font-family="Avenir Next, Helvetica, Arial, sans-serif" font-size="34" font-weight="600">The structure beneath every site</text>
  <path d="M116 386H910" stroke="${palette.navy}" stroke-width="4"/>
  ${station(1, 'Define', 116, 430, palette.amber)}
  ${station(2, 'Connect', 350, 430, palette.blue)}
  ${station(3, 'Resolve', 604, 430, palette.emerald)}
  ${station(4, 'Extend', 116, 506, palette.coral)}
  ${layerRail(140, 686, 730)}
  <path d="M910 574C1080 574 1040 474 1170 474" fill="none" stroke="${palette.amber}" stroke-width="8" stroke-dasharray="18 14"/>
  <path d="m1145 450 30 24-30 24" fill="none" stroke="${palette.amber}" stroke-width="8"/>
  ${wireframePageStructure(1120, 120, 980, 360, 'hero')}
  ${wireframeSettings(2160, 246, 620, 300, 'hero')}
  <path d="M1390 510V650H2430V570" fill="none" stroke="${palette.blue}" stroke-width="7"/>
  <circle cx="1390" cy="510" r="10" fill="${palette.blue}" stroke="${palette.navy}" stroke-width="3"/>
  <circle cx="2430" cy="570" r="10" fill="${palette.emerald}" stroke="${palette.navy}" stroke-width="3"/>
</svg>`
}

function cardSvg() {
    return `<svg xmlns="http://www.w3.org/2000/svg" width="800" height="500" viewBox="0 0 800 500">
  <title>Capell Core architectural engraving</title>
  ${definitions('card')}
  ${environment(800, 500, 'card', 'xMidYMid slice')}
  <rect x="22" y="22" width="756" height="456" rx="14" fill="${palette.paper}" fill-opacity=".56" stroke="${palette.navy}" stroke-width="2"/>
  ${lockup(42, 38, 162, 211, 45)}
  <text x="44" y="137" fill="${palette.navy}" font-family="Avenir Next, Helvetica, Arial, sans-serif" font-size="15" font-weight="700">The structure beneath every site</text>
  ${wireframePageStructure(365, 42, 379, 214, 'card')}
  <path d="M44 286H744" stroke="${palette.navy}" stroke-width="4"/>
  ${station(1, 'Define', 44, 266, palette.amber, true)}
  ${station(2, 'Resolve', 250, 266, palette.blue, true)}
  ${station(3, 'Extend', 470, 266, palette.emerald, true)}
  ${layerRail(82, 392, 630)}
</svg>`
}

function render(svg, name, width, height, outputRelativePath) {
    const sourcePath = path.join(temporaryRoot, `${name}.svg`)
    const pngPath = path.join(temporaryRoot, `${name}.png`)
    const outputPath = path.join(root, outputRelativePath)

    fs.writeFileSync(sourcePath, svg)
    fs.mkdirSync(path.dirname(outputPath), { recursive: true })
    execFileSync('rsvg-convert', [
        '--width',
        String(width),
        '--height',
        String(height),
        '--output',
        pngPath,
        sourcePath,
    ])
    execFileSync('magick', [
        pngPath,
        '-colorspace',
        'sRGB',
        '-sampling-factor',
        '4:4:4',
        '-interlace',
        'Plane',
        '-quality',
        '88',
        '-strip',
        outputPath,
    ])
}

render(heroSvg(), 'core-hero', 2880, 960, outputs.hero)
render(cardSvg(), 'core-card', 800, 500, outputs.card)

fs.rmSync(temporaryRoot, { recursive: true, force: true })

console.log('Rendered Capell Core engraving hero and marketplace card.')
