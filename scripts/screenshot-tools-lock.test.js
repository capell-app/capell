const test = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')

const root = path.resolve(__dirname, '..')
const lock = JSON.parse(
    fs.readFileSync(path.join(root, 'package-lock.json'), 'utf8'),
)
const entry = lock.packages['../capell-screenshot-tools']

test('lock records the screenshot-tools overlay with its pinned dependency graph', () => {
    assert.equal(entry.name, '@capell-app/screenshot-tools')
    assert.equal(entry.dependencies.playwright, '1.61.1')
    assert.equal(
        lock.packages['node_modules/@capell-app/screenshot-tools'].resolved,
        '../capell-screenshot-tools',
    )
})

test('lock exposes the same bin manifest as the installed overlay', (t) => {
    const manifestPath = path.resolve(
        root,
        '../capell-screenshot-tools/package.json',
    )

    if (!fs.existsSync(manifestPath)) {
        t.skip('screenshot-tools overlay is not installed beside this checkout')

        return
    }

    const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'))

    assert.deepEqual(entry.bin, manifest.bin)
    assert.equal(entry.version, manifest.version)
    assert.deepEqual(entry.dependencies, manifest.dependencies)
})

test('lock always lists the receipt validator binary', () => {
    assert.equal(
        entry.bin['capell-screenshot-receipts'],
        'src/receipt-validator-cli.mjs',
    )
})
