const fs = require('fs')
const path = require('path')
const { execFileSync } = require('child_process')

const root = path.resolve(__dirname, '../..')

for (const packageName of ['admin', 'frontend', 'installer', 'marketplace']) {
    for (const [asset, geometry] of [
        ['docs/assets/readme/hero.jpg', '2880x960'],
        ['docs/assets/marketplace/extension-card.jpg', '800x500'],
    ]) {
        const relativePath = `packages/${packageName}/${asset}`
        const absolutePath = path.join(root, relativePath)
        const metadata = execFileSync('identify', [
            '-format',
            '%wx%h|%[colorspace]|%[interlace]|%m|%[EXIF:*]|%c',
            absolutePath,
        ]).toString()

        if (metadata !== `${geometry}|sRGB|JPEG|JPEG||`) {
            throw new Error(`${relativePath} has invalid metadata: ${metadata}`)
        }

        if (fs.statSync(absolutePath).size > 1_500_000) {
            throw new Error(`${relativePath} exceeds 1.5 MB`)
        }
    }
}

for (const relativePath of [
    'artwork/foundation-series/render.sh',
    'artwork/foundation-series/backgrounds',
]) {
    if (fs.existsSync(path.join(root, relativePath))) {
        throw new Error(`Obsolete artwork generator remains: ${relativePath}`)
    }
}

console.log('Capell package Nano Banana artwork contract passed.')
