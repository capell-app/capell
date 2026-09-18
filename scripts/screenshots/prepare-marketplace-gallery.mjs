import { createHash } from 'node:crypto'
import { mkdir, readFile, realpath, writeFile } from 'node:fs/promises'
import { dirname, resolve, sep } from 'node:path'
import { pathToFileURL } from 'node:url'

export async function prepareMarketplaceGallery(config) {
    const environment = { ...process.env, ...(config.environment ?? {}) }
    const receiptPath = environment.CAPELL_SCREENSHOT_GALLERY_RECEIPT
    const sourceRoot = environment.CAPELL_SCREENSHOT_GALLERY_ROOT
    if (!receiptPath || !sourceRoot) {
        throw new Error(
            'Marketplace gallery capture requires CAPELL_SCREENSHOT_GALLERY_RECEIPT and CAPELL_SCREENSHOT_GALLERY_ROOT for genuine SEO Suite runner captures; wireframe placeholders are not evidence.',
        )
    }
    const report = JSON.parse(await readFile(receiptPath, 'utf8'))
    const validator = environment.CAPELL_SCREENSHOT_RUNNER_PATH
        ? pathToFileURL(
              resolve(
                  environment.CAPELL_SCREENSHOT_RUNNER_PATH,
                  'src/receipt-validator.mjs',
              ),
          ).href
        : '@capell-app/screenshot-tools/receipt-validator'
    const { assertValidReceiptReport } = await import(validator)
    assertValidReceiptReport(report)
    const root = await realpath(sourceRoot)
    const receipts = report.provenance.receipts.filter(
        (receipt) =>
            receipt.package === 'seo-suite' &&
            receipt.acceptance === 'accepted' &&
            receipt.capture.colorScheme === 'light' &&
            receipt.capture.scenario !== 'static-html',
    )
    if (receipts.length < 2) {
        throw new Error(
            'The gallery needs at least two accepted, route-backed SEO Suite captures.',
        )
    }
    const images = []
    const ids = new Set()
    for (const receipt of receipts) {
        if (ids.has(receipt.id)) continue
        const source = await realpath(resolve(root, receipt.output.path))
        if (!source.startsWith(root + sep))
            throw new Error('Gallery source escapes its repository.')
        const bytes = await readFile(source)
        if (
            bytes.subarray(0, 8).toString('hex') !== '89504e470d0a1a0a' ||
            createHash('sha256').update(bytes).digest('hex') !==
                receipt.output.sha256
        ) {
            throw new Error(
                `Gallery PNG does not match its runner receipt: ${receipt.id}`,
            )
        }
        const filename = `${images.length + 1}.png`
        images.push({
            filename,
            caption: receipt.id.replaceAll('-', ' '),
            sha256: receipt.output.sha256,
        })
        ids.add(receipt.id)
        // Publish only receipt-verified bytes, never generate or edit screenshots.
        const destination = resolve(
            config.appPath,
            'workbench/database/screenshot-gallery',
            filename,
        )
        await mkdir(dirname(destination), { recursive: true })
        await writeFile(destination, bytes)
    }
    if (images.length < 2)
        throw new Error('The gallery needs two distinct captures.')
    await writeFile(
        resolve(
            config.appPath,
            'workbench/database/screenshot-gallery/images.json',
        ),
        JSON.stringify(images, null, 2) + '\n',
    )
    console.log(
        `Prepared ${images.length} receipt-verified SEO Suite gallery images.`,
    )
}
