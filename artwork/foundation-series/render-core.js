const fs = require('fs')
const os = require('os')
const path = require('path')
const { execFileSync } = require('child_process')

const root = path.resolve(__dirname, '../..')
const temporaryRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'capell-core-art-'))

const palette = {
    paper: '#f7f0e3',
    paperLight: '#fffaf0',
    navy: '#10233f',
    navyDeep: '#07172d',
    line: '#b8ad9a',
    muted: '#6e716f',
    amber: '#e3a338',
    blue: '#3b82c4',
    emerald: '#2f8f78',
    white: '#ffffff',
}

const inputs = {
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

function definitions(prefix) {
    return `<defs>
    <pattern id="${prefix}-grid" width="36" height="36" patternUnits="userSpaceOnUse">
      <path d="M36 0H0V36" fill="none" stroke="${palette.line}" stroke-width="1" opacity=".32"/>
      <circle cx="4" cy="4" r="1.3" fill="${palette.navy}" opacity=".2"/>
    </pattern>
    <filter id="${prefix}-shadow" x="-30%" y="-30%" width="160%" height="180%">
      <feDropShadow dx="0" dy="18" stdDeviation="18" flood-color="${palette.navyDeep}" flood-opacity=".2"/>
    </filter>
    <clipPath id="${prefix}-page-clip"><rect x="0" y="0" width="1000" height="520" rx="8"/></clipPath>
    <clipPath id="${prefix}-settings-clip"><rect x="0" y="0" width="650" height="320" rx="8"/></clipPath>
  </defs>`
}

function paper(width, height, prefix) {
    return `<rect width="${width}" height="${height}" fill="${palette.paper}"/>
  <rect width="${width}" height="${height}" fill="url(#${prefix}-grid)"/>
  <path d="M0 ${height * 0.8}C${width * 0.24} ${height * 0.72} ${width * 0.42} ${height * 0.9} ${width} ${height * 0.7}V${height}H0Z" fill="${palette.amber}" opacity=".06"/>`
}

function logo(x, y, width) {
    return `<image href="${assetData(inputs.logo)}" x="${x}" y="${y}" width="${width}" preserveAspectRatio="xMinYMin meet"/>`
}

function screenFrame({ x, y, width, height, source, clipId, label, accent }) {
    return `<g transform="translate(${x} ${y})" filter="url(#hero-shadow)">
    <rect x="-16" y="-16" width="${width + 32}" height="${height + 58}" rx="12" fill="${palette.navyDeep}"/>
    <circle cx="12" cy="${height + 20}" r="6" fill="${accent}"/>
    <text x="30" y="${height + 26}" fill="${palette.white}" font-family="Avenir Next, Helvetica, Arial, sans-serif" font-size="18" font-weight="700" letter-spacing="2">${label.toUpperCase()}</text>
    <g clip-path="url(#${clipId})">
      <image href="${assetData(source)}" width="${width}" height="${height}" preserveAspectRatio="xMidYMid slice"/>
    </g>
  </g>`
}

function foundationLayer(x, y, width, label, index) {
    const offset = index * 9
    const accent = [palette.amber, palette.blue, palette.emerald][index % 3]

    return `<g transform="translate(${x + offset} ${y})">
    <path d="M0 0H${width}L${width - 48} 58H48Z" fill="${index % 2 === 0 ? palette.navy : palette.navyDeep}" stroke="${palette.paper}" stroke-width="2"/>
    <rect x="56" y="17" width="13" height="13" fill="${accent}"/>
    <text x="86" y="31" fill="${palette.white}" font-family="Avenir Next, Helvetica, Arial, sans-serif" font-size="20" font-weight="700" letter-spacing="1.5">${label.toUpperCase()}</text>
    <path d="M${width - 150} 18h54l18 18h-90z" fill="none" stroke="${accent}" stroke-width="4"/>
  </g>`
}

function station(index, label, x, y, color, compact = false) {
    const box = compact ? 34 : 48
    const fontSize = compact ? 15 : 20

    return `<g transform="translate(${x} ${y})">
    <rect width="${box}" height="${box}" rx="4" fill="${color}" stroke="${palette.navy}" stroke-width="3"/>
    <text x="${box / 2}" y="${compact ? 23 : 32}" text-anchor="middle" fill="${palette.white}" font-family="Avenir Next, Helvetica, Arial, sans-serif" font-size="${fontSize}" font-weight="800">${index}</text>
    <text x="${box + 14}" y="${compact ? 23 : 32}" fill="${palette.navy}" font-family="Avenir Next, Helvetica, Arial, sans-serif" font-size="${fontSize}" font-weight="800" letter-spacing="1">${label.toUpperCase()}</text>
  </g>`
}

function machine(x, y, scale = 1) {
    return `<g transform="translate(${x} ${y}) scale(${scale})">
    <rect x="0" y="22" width="104" height="72" rx="4" fill="${palette.paperLight}" stroke="${palette.navy}" stroke-width="5"/>
    <circle cx="35" cy="57" r="17" fill="none" stroke="${palette.blue}" stroke-width="7"/>
    <path d="M73 43h18M73 58h18M73 73h18" stroke="${palette.navy}" stroke-width="5"/>
    <path d="M18 22V0h68v22M22 94v18M82 94v18" fill="none" stroke="${palette.navy}" stroke-width="5"/>
  </g>`
}

function heroSvg() {
    const layers = [
        'Extension',
        'Theme',
        'Settings',
        'URL',
        'Page',
        'Language',
        'Site',
    ]

    return `<svg xmlns="http://www.w3.org/2000/svg" width="2880" height="960" viewBox="0 0 2880 960">
  <title>Capell Core — The structure beneath every site</title>
  ${definitions('hero')}
  ${paper(2880, 960, 'hero')}
  ${logo(110, 82, 440)}
  <text x="110" y="430" fill="${palette.navy}" font-family="Avenir Next, Helvetica, Arial, sans-serif" font-size="132" font-weight="900" letter-spacing="10">CORE</text>
  <text x="116" y="500" fill="${palette.navy}" font-family="Avenir Next, Helvetica, Arial, sans-serif" font-size="34" font-weight="600">The structure beneath every site</text>
  <path d="M116 554H900" stroke="${palette.navy}" stroke-width="6"/>
  ${station(1, 'Define', 116, 590, palette.amber)}
  ${station(2, 'Connect', 390, 590, palette.blue)}
  ${station(3, 'Resolve', 686, 590, palette.emerald)}
  ${station(4, 'Extend', 116, 678, palette.amber)}
  <text x="118" y="810" fill="${palette.muted}" font-family="Avenir Next, Helvetica, Arial, sans-serif" font-size="17" font-weight="700" letter-spacing="3">CAPELL FOUNDATION / STRUCTURAL SYSTEM 04</text>
  <path d="M1060 680H2670" fill="none" stroke="${palette.navy}" stroke-width="8"/>
  <path d="M1110 680V610H1470V680M2130 680V590H2540V680" fill="none" stroke="${palette.blue}" stroke-width="7"/>
  <path d="M1470 610h660" stroke="${palette.amber}" stroke-width="7" stroke-dasharray="18 12"/>
  <path d="m2106 590 26 20-26 20" fill="none" stroke="${palette.amber}" stroke-width="7"/>
  ${layers.map((label, index) => foundationLayer(1060, 706 + index * 34, 1610 - index * 62, label, index)).join('\n  ')}
  ${screenFrame({ x: 1190, y: 92, width: 1000, height: 520, source: inputs.pageStructure, clipId: 'hero-page-clip', label: 'Page hierarchy / operational window', accent: palette.amber })}
  ${screenFrame({ x: 2120, y: 320, width: 650, height: 320, source: inputs.settings, clipId: 'hero-settings-clip', label: 'Settings / configuration', accent: palette.emerald })}
  ${machine(1000, 540, 0.72)}
  ${machine(2580, 652, 0.58)}
  <path d="M1090 510V252H1162" fill="none" stroke="${palette.navy}" stroke-width="4"/>
  <circle cx="1090" cy="510" r="10" fill="${palette.amber}" stroke="${palette.navy}" stroke-width="4"/>
  <text x="1066" y="228" fill="${palette.navy}" font-family="Avenir Next, Helvetica, Arial, sans-serif" font-size="16" font-weight="800" letter-spacing="2">STRUCTURE IN VIEW</text>
</svg>`
}

function cardSvg() {
    return `<svg xmlns="http://www.w3.org/2000/svg" width="800" height="500" viewBox="0 0 800 500">
  <title>Capell Core architectural foundation cutaway</title>
  ${definitions('card')}
  ${paper(800, 500, 'card')}
  ${logo(38, 28, 196)}
  <text x="42" y="182" fill="${palette.navy}" font-family="Avenir Next, Helvetica, Arial, sans-serif" font-size="64" font-weight="900" letter-spacing="6">CORE</text>
  <text x="44" y="215" fill="${palette.navy}" font-family="Avenir Next, Helvetica, Arial, sans-serif" font-size="15" font-weight="700">The structure beneath every site</text>
  <g transform="translate(318 42)" filter="url(#card-shadow)">
    <rect x="-10" y="-10" width="450" height="230" rx="8" fill="${palette.navyDeep}"/>
    <image href="${assetData(inputs.pageStructure)}" width="430" height="190" preserveAspectRatio="xMidYMid slice"/>
    <rect y="190" width="430" height="30" fill="${palette.navyDeep}"/>
    <circle cx="18" cy="205" r="5" fill="${palette.amber}"/>
    <text x="31" y="211" fill="${palette.white}" font-family="Avenir Next, Helvetica, Arial, sans-serif" font-size="11" font-weight="700" letter-spacing="1.5">PAGE HIERARCHY</text>
  </g>
  <path d="M40 304H758" stroke="${palette.navy}" stroke-width="5"/>
  ${station(1, 'Define', 42, 274, palette.amber, true)}
  ${station(2, 'Resolve', 252, 274, palette.blue, true)}
  ${station(3, 'Extend', 476, 274, palette.emerald, true)}
  ${foundationLayer(66, 344, 650, 'Extension', 0)}
  ${foundationLayer(74, 382, 600, 'Theme / Settings', 1)}
  ${foundationLayer(82, 420, 550, 'Page / URL', 2)}
  ${foundationLayer(90, 458, 500, 'Site / Language', 3)}
  ${machine(642, 394, 0.68)}
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

console.log('Rendered Capell Core hero and marketplace card.')
