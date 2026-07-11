const fs = require('fs')
const path = require('path')
const { execFileSync } = require('child_process')

const root = path.resolve(__dirname, '../..')
const outputs = [
    ['packages/core/docs/assets/readme/hero.jpg', '2880x960'],
    ['packages/core/docs/assets/marketplace/extension-card.jpg', '800x500'],
]
for (const [relative, geometry] of outputs) {
    const file = path.join(root, relative)
    const metadata = execFileSync('identify', [
        '-format',
        '%wx%h|%[colorspace]|%[interlace]|%m',
        file,
    ]).toString()
    if (metadata !== `${geometry}|sRGB|JPEG|JPEG`)
        throw new Error(`${relative} has invalid metadata: ${metadata}`)
    if (fs.statSync(file).size > 1_500_000)
        throw new Error(`${relative} exceeds 1.5 MB`)
}

const renderer = fs.readFileSync(
    path.join(root, 'artwork/foundation-series/render-core.js'),
    'utf8',
)
for (const required of [
    'The structure beneath every site',
    'LARAVEL APPLICATION',
    'CAPELL CORE',
    'SITE + LANGUAGE',
    'PAGE + URL',
    'SETTINGS + THEME',
    'ADMIN',
    'APPLICATION-OWNED FRONTEND',
    'COMPOSER PACKAGE',
    'extension socket',
    'Define',
    'Connect',
    'Resolve',
    'Extend',
    'Reuse',
    'Cormorant Garamond, Didot, Georgia, serif',
    'factualPageStructureReference',
    'factualSettingsReference',
])
    if (!renderer.includes(required))
        throw new Error(`Renderer is missing required contract: ${required}`)

for (const forbidden of [
    'station(',
    'layerRail(',
    'assetData(inputs.pageStructure)',
    'assetData(inputs.settings)',
    'traffic-light',
    'gear',
    'conveyor',
    'piston',
]) {
    if (renderer.toLowerCase().includes(forbidden.toLowerCase()))
        throw new Error(`Renderer retains rejected treatment: ${forbidden}`)
}
for (const relative of [
    'artwork/foundation-series/backgrounds/core-engraving.jpg',
    'artwork/foundation-series/capell-logo.svg',
    'packages/core/docs/images/screenshots/core-page-structure.png',
    'packages/core/docs/images/screenshots/core-settings-backed-configuration-dark.png',
]) {
    if (!fs.existsSync(path.join(root, relative)))
        throw new Error(`Missing artwork input: ${relative}`)
}
console.log('Core structural spine artwork contract passed.')
