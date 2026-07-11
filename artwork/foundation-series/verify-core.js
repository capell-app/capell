const fs = require('fs')
const path = require('path')
const { execFileSync } = require('child_process')

const root = path.resolve(__dirname, '../..')
const outputs = [
    ['packages/core/docs/assets/readme/hero.jpg', '2880x960'],
    ['packages/core/docs/assets/marketplace/extension-card.jpg', '800x500'],
]

for (const [relativePath, geometry] of outputs) {
    const absolutePath = path.join(root, relativePath)
    const metadata = execFileSync('identify', [
        '-format',
        '%wx%h|%[colorspace]|%[interlace]|%m',
        absolutePath,
    ]).toString()

    if (metadata !== `${geometry}|sRGB|JPEG|JPEG`) {
        throw new Error(`${relativePath} has invalid metadata: ${metadata}`)
    }

    if (fs.statSync(absolutePath).size > 1_500_000) {
        throw new Error(`${relativePath} exceeds 1.5 MB`)
    }
}

for (const relativePath of [
    'artwork/foundation-series/capell-logo.svg',
    'artwork/foundation-series/references/capell-logo-reference.png',
    'artwork/foundation-series/reviews/2026-07-11-core-structural-spine-review.md',
]) {
    if (!fs.existsSync(path.join(root, relativePath))) {
        throw new Error(`Missing Core artwork evidence: ${relativePath}`)
    }
}

for (const forbiddenPath of [
    'artwork/foundation-series/render-core.js',
    'artwork/foundation-series/backgrounds/core-engraving.jpg',
]) {
    if (fs.existsSync(path.join(root, forbiddenPath))) {
        throw new Error(`Obsolete Core generator remains: ${forbiddenPath}`)
    }
}

const legacyRenderer = fs.readFileSync(
    path.join(root, 'artwork/foundation-series/render.sh'),
    'utf8',
)

if (legacyRenderer.includes('render_core')) {
    throw new Error('Legacy renderer still invokes the Core artwork generator')
}

console.log('Core Nano Banana artwork contract passed.')
