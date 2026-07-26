#!/usr/bin/env node
//
// Render docs/images/capell-readme-banner.jpg from readme-banner.html.
//
// The banner used to be a hand-made raster with no source, so its wording drifted
// away from the product's own messaging and nobody could correct it. Keeping the
// composition as HTML means the copy, the package list, and the install commands
// stay editable, and the canonical logo is embedded as vector rather than redrawn.

import fs from 'node:fs'
import path from 'node:path'
import { chromium } from 'playwright'

const repositoryRoot = path.resolve(import.meta.dirname, '..', '..')
const templatePath = path.join(
    repositoryRoot,
    'scripts/screenshots/readme-banner.html',
)
const logoPath = path.join(
    repositoryRoot,
    'artwork/foundation-series/capell-logo.svg',
)
const outputPath = path.join(
    repositoryRoot,
    'docs/images/capell-readme-banner.jpg',
)

const WIDTH = 1440
const HEIGHT = 480

async function main() {
    const logoDataUri =
        'data:image/svg+xml;base64,' +
        fs.readFileSync(logoPath).toString('base64')
    const html = fs
        .readFileSync(templatePath, 'utf8')
        .replace('LOGO_SRC', logoDataUri)

    const browser = await chromium.launch()

    try {
        const page = await browser.newPage({
            viewport: { width: WIDTH, height: HEIGHT },
            deviceScaleFactor: 2,
        })

        await page.setContent(html, { waitUntil: 'networkidle' })
        await page.screenshot({ path: outputPath, type: 'jpeg', quality: 90 })

        const overflowed = await page.evaluate(
            ([width, height]) =>
                document.body.scrollWidth > width ||
                document.body.scrollHeight > height,
            [WIDTH, HEIGHT],
        )

        if (overflowed) {
            throw new Error(
                'Banner content overflows the fixed canvas; adjust the template.',
            )
        }
    } finally {
        await browser.close()
    }

    process.stdout.write(
        `Wrote ${path.relative(repositoryRoot, outputPath)} at ${WIDTH * 2}x${HEIGHT * 2}.\n`,
    )
}

await main()
