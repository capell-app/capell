const fs = require('node:fs')
const { spawnSync } = require('node:child_process')
const path = require('node:path')

const repositoryRoot = path.resolve(__dirname, '..')

function runnerRoot() {
    if (process.env.CAPELL_SCREENSHOT_RUNNER_PATH) {
        return path.resolve(process.env.CAPELL_SCREENSHOT_RUNNER_PATH)
    }

    return path.join(
        repositoryRoot,
        'node_modules',
        '@capell-app',
        'screenshot-tools',
    )
}

function receiptValidatorPath() {
    return path.join(runnerRoot(), 'src', 'receipt-validator-cli.mjs')
}

function discoverReceiptFiles(root = repositoryRoot) {
    const receiptRoot = path.join(root, 'docs', 'screenshot-receipts')

    if (!fs.existsSync(receiptRoot)) return []

    return fs
        .readdirSync(receiptRoot, { recursive: true, withFileTypes: true })
        .filter((entry) => entry.isFile() && entry.name.endsWith('.json'))
        .map((entry) => path.join(entry.parentPath, entry.name))
        .sort()
}

function validateLocalReceipts(receiptFiles = discoverReceiptFiles()) {
    if (receiptFiles.length === 0) {
        console.log('No local runner screenshot receipts to validate.')

        return 0
    }

    const validator = receiptValidatorPath()

    if (!fs.existsSync(validator)) {
        console.error(
            `Runner receipt validator is unavailable at ${validator}. ` +
                'Install the declared runner dependency or set CAPELL_SCREENSHOT_RUNNER_PATH.',
        )

        return 1
    }

    const result = spawnSync(process.execPath, [validator, ...receiptFiles], {
        cwd: repositoryRoot,
        env: process.env,
        stdio: 'inherit',
    })

    if (result.error) console.error(result.error.message)

    return result.status ?? 1
}

if (require.main === module) {
    process.exitCode = validateLocalReceipts()
}

module.exports = {
    discoverReceiptFiles,
    receiptValidatorPath,
    runnerRoot,
    validateLocalReceipts,
}
