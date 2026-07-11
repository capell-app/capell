const fs = require('fs')
const path = require('path')
const { execFileSync } = require('child_process')

const root = path.resolve(__dirname, '../..')
const requiredAssets = [
    ['packages/core/docs/assets/readme/hero.jpg', '2880x960'],
    ['packages/core/docs/assets/marketplace/extension-card.jpg', '800x500'],
]

for (const [relativePath, geometry] of requiredAssets) {
    const absolutePath = path.join(root, relativePath)
    const metadata = execFileSync('identify', [
        '-format',
        '%wx%h|%[colorspace]|%[interlace]',
        absolutePath,
    ]).toString()

    if (metadata !== `${geometry}|sRGB|JPEG`) {
        throw new Error(`${relativePath} has invalid metadata: ${metadata}`)
    }

    if (fs.statSync(absolutePath).size > 1_500_000) {
        throw new Error(`${relativePath} exceeds the 1.5 MB artwork limit`)
    }
}

const renderer = fs.readFileSync(
    path.join(root, 'artwork/foundation-series/render-core.js'),
    'utf8',
)

for (const requiredText of [
    'The structure beneath every site',
    'Define',
    'Connect',
    'Resolve',
    'Extend',
    'Site',
    'Language',
    'Page',
    'URL',
    'Settings',
    'Theme',
    'Extension',
]) {
    if (!renderer.includes(requiredText)) {
        throw new Error(`Renderer is missing required text: ${requiredText}`)
    }
}

const requiredInputs = [
    'artwork/foundation-series/backgrounds/core-engraving.jpg',
    'artwork/foundation-series/capell-logo.svg',
    'packages/core/docs/images/screenshots/core-page-structure.png',
    'packages/core/docs/images/screenshots/core-settings-backed-configuration-dark.png',
]

for (const relativePath of requiredInputs) {
    if (!fs.existsSync(path.join(root, relativePath))) {
        throw new Error(
            `Required artwork input does not exist: ${relativePath}`,
        )
    }
}

for (const requiredSource of [
    'wireframePageStructure',
    'wireframeSettings',
    'Cormorant Garamond, Didot, Georgia, serif',
    'inputs.environment',
]) {
    if (!renderer.includes(requiredSource)) {
        throw new Error(`Renderer is missing revised source: ${requiredSource}`)
    }
}

for (const forbiddenEmbedding of [
    'assetData(inputs.pageStructure)',
    'assetData(inputs.settings)',
]) {
    if (renderer.includes(forbiddenEmbedding)) {
        throw new Error(
            `Renderer embeds a full screenshot: ${forbiddenEmbedding}`,
        )
    }
}

console.log('Core engraving artwork contract passed.')
