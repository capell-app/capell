import assert from 'node:assert/strict'
import { spawn } from 'node:child_process'
import fs from 'node:fs'
import net from 'node:net'
import os from 'node:os'
import path from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

const repositoryRoot = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    '../..',
)

const freePort = () =>
    new Promise((resolve, reject) => {
        const probe = net.createServer()
        probe.once('error', reject)
        probe.listen(0, '127.0.0.1', () => {
            const { port } = probe.address()
            probe.close(() => resolve(port))
        })
    })

const requestOnce = async (port, attempts = 50) => {
    for (let attempt = 0; attempt < attempts; attempt += 1) {
        try {
            const response = await fetch(`http://127.0.0.1:${port}/`)
            return await response.text()
        } catch {
            await new Promise((resolve) => setTimeout(resolve, 100))
        }
    }
    throw new Error('The PHP development server never answered.')
}

// A development server worker that keeps PHP's 60 second input timer armed
// terminates with "Maximum execution time of 0+2 seconds exceeded" long after
// the request that armed it, failing whichever theme batch is capturing then.
test('the capture server children run without a request input timeout', async (context) => {
    const documentRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'capture-ini-'))
    context.after(() =>
        fs.rmSync(documentRoot, { recursive: true, force: true }),
    )
    fs.writeFileSync(
        path.join(documentRoot, 'index.php'),
        '<?php echo ini_get("max_input_time");',
    )

    const { default: config } = await import(
        path.join(repositoryRoot, "screenshots.config.mjs")
    )
    const port = await freePort()
    // Laravel's serve command starts the real `php -S` child without the
    // parent's -d flags, so only the scanned ini directory reaches it.
    const environment = {
        ...process.env,
        PHP_INI_SCAN_DIR: config.app.serve.environment.PHP_INI_SCAN_DIR,
    }
    delete environment.PHPRC
    const server = spawn(
        'php',
        ['-S', `127.0.0.1:${port}`, '-t', documentRoot],
        { env: environment, stdio: 'ignore' },
    )
    context.after(() => server.kill('SIGTERM'))

    const maxInputTime = Number.parseInt(await requestOnce(port), 10)

    assert.ok(
        maxInputTime <= 0,
        `max_input_time is ${maxInputTime}; a positive value arms a timer that kills idle workers`,
    )
})
