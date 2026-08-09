const assert = require('node:assert/strict')
const fs = require('node:fs')
const os = require('node:os')
const path = require('node:path')
const test = require('node:test')

const {
    discoverReceiptFiles,
    receiptValidatorPath,
    validateLocalReceipts,
} = require('./check-runner-screenshot-receipts')

test('discovers only durable JSON receipts below the repository receipt directory', () => {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'capell-receipts-'))

    try {
        const receiptDirectory = path.join(
            root,
            'docs',
            'screenshot-receipts',
            'cap0133',
        )
        fs.mkdirSync(receiptDirectory, { recursive: true })
        fs.writeFileSync(path.join(receiptDirectory, 'valid.json'), '{}')
        fs.writeFileSync(path.join(receiptDirectory, 'notes.md'), 'not evidence')

        assert.deepEqual(discoverReceiptFiles(root), [
            path.join(receiptDirectory, 'valid.json'),
        ])
    } finally {
        fs.rmSync(root, { recursive: true, force: true })
    }
})

test('fails closed when receipts exist but the shared validator is unavailable', () => {
    const previous = process.env.CAPELL_SCREENSHOT_RUNNER_PATH
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'missing-runner-'))
    process.env.CAPELL_SCREENSHOT_RUNNER_PATH = root

    try {
        assert.equal(validateLocalReceipts(['/tmp/receipt.json']), 1)
    } finally {
        fs.rmSync(root, { recursive: true, force: true })

        if (previous === undefined) {
            delete process.env.CAPELL_SCREENSHOT_RUNNER_PATH
        } else {
            process.env.CAPELL_SCREENSHOT_RUNNER_PATH = previous
        }
    }
})

test('uses the configured shared runner validator without companion-package coupling', () => {
    const previous = process.env.CAPELL_SCREENSHOT_RUNNER_PATH
    process.env.CAPELL_SCREENSHOT_RUNNER_PATH = '/tmp/capell-screenshot-runner'

    try {
        assert.equal(
            receiptValidatorPath(),
            '/tmp/capell-screenshot-runner/src/receipt-validator-cli.mjs',
        )
    } finally {
        if (previous === undefined) {
            delete process.env.CAPELL_SCREENSHOT_RUNNER_PATH
        } else {
            process.env.CAPELL_SCREENSHOT_RUNNER_PATH = previous
        }
    }
})
