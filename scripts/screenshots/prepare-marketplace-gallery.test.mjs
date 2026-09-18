import assert from 'node:assert/strict'
import { createHash } from 'node:crypto'
import { mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises'
import { resolve } from 'node:path'
import test from 'node:test'
import { prepareMarketplaceGallery } from './prepare-marketplace-gallery.mjs'

// Unit-test bytes only; these never become documentation captures or receipts.
const png = Buffer.from('89504e470d0a1a0a00000000', 'hex')

async function fixture(t) {
    const root = resolve('storage/framework/testing')
    await mkdir(root, { recursive: true })
    const directory = await mkdtemp(resolve(root, 'gallery-unit-'))
    t.after(() => rm(directory, { recursive: true, force: true }))
    const source = resolve(directory, 'source')
    await mkdir(source)
    const report = JSON.parse(
        await readFile(
            'docs/screenshot-receipts/cap0100/targeted-authenticated-queue.json',
            'utf8',
        ),
    )
    const template = report.provenance.receipts[0]
    report.provenance.receipts = ['seo-audit', 'seo-settings'].map((id) => ({
        ...structuredClone(template),
        package: 'seo-suite',
        id,
        output: {
            path: `${id}.png`,
            sha256: createHash('sha256').update(png).digest('hex'),
        },
    }))
    report.captured = 2
    const receiptPath = resolve(directory, 'unit-receipt.json')
    await writeFile(receiptPath, JSON.stringify(report))
    for (const receipt of report.provenance.receipts)
        await writeFile(resolve(source, receipt.output.path), png)
    return {
        directory,
        source,
        report,
        receiptPath,
        config: {
            appPath: directory,
            environment: {
                CAPELL_SCREENSHOT_GALLERY_RECEIPT: receiptPath,
                CAPELL_SCREENSHOT_GALLERY_ROOT: source,
            },
        },
    }
}

test('refuses gallery capture without provenance inputs', async () => {
    await assert.rejects(
        prepareMarketplaceGallery({
            environment: {
                CAPELL_SCREENSHOT_GALLERY_RECEIPT: '',
                CAPELL_SCREENSHOT_GALLERY_ROOT: '',
            },
        }),
        /requires CAPELL_SCREENSHOT_GALLERY_RECEIPT/,
    )
})

test('publishes only gallery bytes bound to accepted route capture receipts', async (t) => {
    const { directory, config } = await fixture(t)
    await prepareMarketplaceGallery(config)
    const manifest = JSON.parse(
        await readFile(
            resolve(
                directory,
                'workbench/database/screenshot-gallery/images.json',
            ),
            'utf8',
        ),
    )
    assert.equal(manifest.length, 2)
    assert.deepEqual(
        await readFile(
            resolve(directory, 'workbench/database/screenshot-gallery/1.png'),
        ),
        png,
    )
})

test('rejects changed image bytes instead of replacing them with placeholders', async (t) => {
    const { source, config } = await fixture(t)
    await writeFile(resolve(source, 'seo-audit.png'), 'not the captured image')
    await assert.rejects(
        prepareMarketplaceGallery(config),
        /does not match its runner receipt/,
    )
})

test('rejects a gallery with only diagnostic evidence', async (t) => {
    const { report, receiptPath, config } = await fixture(t)
    report.provenance.receipts.forEach((receipt) => {
        receipt.acceptance = 'diagnostic-only'
    })
    await writeFile(receiptPath, JSON.stringify(report))
    await assert.rejects(
        prepareMarketplaceGallery(config),
        /at least two accepted/,
    )
})
