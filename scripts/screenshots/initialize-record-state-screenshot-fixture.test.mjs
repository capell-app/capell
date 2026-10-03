import assert from 'node:assert/strict'
import test from 'node:test'
import { spawn, spawnSync } from 'node:child_process'
import { mkdtempSync, readFileSync, writeFileSync, rmSync } from 'node:fs'
import { once } from 'node:events'
import { createServer } from 'node:net'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'

import {
    commandsForEntries,
    fixtureEnvironment,
} from './initialize-record-state-screenshot-fixture.mjs'

test('selects the frontend fixture only for the published frontend entry', () => {
    assert.deepEqual(
        commandsForEntries(
            [{ id: 'frontend-published-page', url: '/' }],
            'http://127.0.0.1:8145',
        ),
        [
            'Workbench\\App\\Support\\FrontendScreenshotSeed::initialize("http://127.0.0.1:8145");',
        ],
    )
    assert.deepEqual(commandsForEntries([{ id: 'frontend-settings' }]), [])
})

test('selects the frontend fixture for a mobile dark published page', () => {
    assert.deepEqual(
        commandsForEntries(
            [{ id: 'frontend-published-page-mobile-dark', url: '/' }],
            'http://127.0.0.1:8145',
        ),
        [
            'Workbench\\App\\Support\\FrontendScreenshotSeed::initialize("http://127.0.0.1:8145");',
        ],
    )
})

test('selects the record-state fixture for documentation aliases', () => {
    for (const id of [
        'docs-media-edit-focal-point',
        'docs-media-edit-localized-metadata',
        'admin-media-edit-form',
        'first-page-edit-settings-tab',
    ]) {
        assert.deepEqual(
            commandsForEntries([
                { id, url: '/screenshot-fixtures/record-states/example' },
            ]),
            [
                'Workbench\\App\\Support\\RecordStateScreenshotFixture::initialize();',
            ],
        )
    }
})

test('passes both app and server environment to fixture initialization', () => {
    assert.deepEqual(
        fixtureEnvironment(
            {
                environment: { DB_DATABASE: '/fixture.sqlite' },
                serve: { environment: { PHPRC: '/fixture/php' } },
            },
            { PATH: '/usr/bin' },
        ),
        {
            PATH: '/usr/bin',
            DB_DATABASE: '/fixture.sqlite',
            PHPRC: '/fixture/php',
        },
    )
})

test('initializes the real installer host for both guide aliases', () => {
    for (const entry of [
        { id: 'install-guide-page', target: 'InstallGuidePage' },
        { id: 'docs-install-guide-page', url: '/install-guide' },
    ]) {
        assert.deepEqual(commandsForEntries([entry]), [
            'Workbench\\App\\Support\\InstallerScreenshotFixture::initialize();',
        ])
    }
})

test('initializes published media for the formerly orphaned media capture', () => {
    assert.equal(
        commandsForEntries(
            [{ id: 'frontend-media-rendering' }],
            'http://127.0.0.1:8145',
        ).length,
        1,
    )
})

for (const inheritedScan of [undefined, '', 'custom']) {
    test(`uses the host PHP ini and appends fixture settings with ${inheritedScan ?? 'default'} scan`, async () => {
        const directory = mkdtempSync(join(tmpdir(), 'core-screenshot-ini-'))
        const previousScan = process.env.PHP_INI_SCAN_DIR
        const previousPhprc = process.env.PHPRC
        const scan = inheritedScan === 'custom' ? directory : inheritedScan
        const cleanEnvironment = {
            ...process.env,
            PHPRC: undefined,
            PHP_INI_SCAN_DIR: undefined,
        }
        const baseline = spawnSync(
            'php',
            ['-r', 'echo php_ini_loaded_file();'],
            { env: cleanEnvironment, encoding: 'utf8' },
        )
        assert.equal(baseline.status, 0, baseline.stderr)
        writeFileSync(join(directory, 'custom.ini'), 'precision=11\n')
        try {
            if (scan === undefined) delete process.env.PHP_INI_SCAN_DIR
            else process.env.PHP_INI_SCAN_DIR = scan
            process.env.PHPRC = join(directory, 'stale-php.ini')
            const { default: config } = await import(
                `../../screenshots.config.mjs?scan=${encodeURIComponent(String(inheritedScan))}`
            )
            const environment = fixtureEnvironment({
                environment: config.environment,
                serve: config.app.serve,
            })
            const child = spawnSync(
                'php',
                [
                    '-r',
                    `echo json_encode(['phprc' => getenv('PHPRC'), 'scan' => getenv('PHP_INI_SCAN_DIR'), 'loaded' => php_ini_loaded_file(), 'memory' => ini_get('memory_limit'), 'precision' => ini_get('precision')]);`,
                ],
                { env: environment, encoding: 'utf8' },
            )
            assert.equal(child.status, 0, child.stderr)
            const actual = JSON.parse(child.stdout)
            assert.equal(actual.phprc, false)
            assert.equal(
                actual.scan,
                `${scan || ''}:${resolve('workbench/php')}`,
            )
            assert.equal(actual.loaded, baseline.stdout)
            assert.equal(actual.memory, '-1')
            if (scan) assert.equal(actual.precision, '11')
        } finally {
            if (previousScan === undefined) delete process.env.PHP_INI_SCAN_DIR
            else process.env.PHP_INI_SCAN_DIR = previousScan
            if (previousPhprc === undefined) delete process.env.PHPRC
            else process.env.PHPRC = previousPhprc
            rmSync(directory, { recursive: true, force: true })
        }
    })
}

test('keeps fixture environment visible to Laravel in the real HTTP child', async () => {
    const directory = mkdtempSync(join(tmpdir(), 'core-screenshot-http-env-'))
    let child
    let closed
    let diagnostics = ''
    try {
        const baseline = spawnSync(
            'php',
            [
                '-r',
                'echo json_encode(["ini" => php_ini_loaded_file(), "extension_dir" => ini_get("extension_dir")]);',
            ],
            {
                env: {
                    ...process.env,
                    PHPRC: undefined,
                    PHP_INI_SCAN_DIR: undefined,
                },
                encoding: 'utf8',
            },
        )
        assert.equal(baseline.status, 0, baseline.stderr)
        const host = JSON.parse(baseline.stdout)
        // Retain the host's extension paths while controlling its GPCS profile.
        const hostIni = join(directory, 'host.ini')
        const hostConfiguration = readFileSync(host.ini, 'utf8')
        writeFileSync(hostIni, `${hostConfiguration}\nvariables_order=GPCS\n`)
        writeFileSync(
            join(directory, 'index.php'),
            `<?php
require getenv('CAPELL_SCREENSHOT_TEST_AUTOLOAD');
Illuminate\\Support\\Env::disablePutenv();
header('Content-Type: application/json');
echo json_encode([
    'values' => array_map(fn ($key) => env($key), ['DB_DATABASE', 'DB_CONNECTION', 'APP_ENV', 'CAPELL_SCREENSHOT_DISPLAY_ORIGIN']),
    'variables_order' => ini_get('variables_order'),
    'memory' => ini_get('memory_limit'),
    'extension_dir' => ini_get('extension_dir'),
], JSON_THROW_ON_ERROR);
`,
        )
        const socket = createServer()
        await new Promise((resolvePromise, rejectPromise) => {
            socket.once('error', rejectPromise)
            socket.listen(0, '127.0.0.1', resolvePromise)
        })
        const { port } = socket.address()
        await new Promise((resolvePromise, rejectPromise) =>
            socket.close((error) =>
                error ? rejectPromise(error) : resolvePromise(),
            ),
        )
        const { default: config } =
            await import('../../screenshots.config.mjs?http-environment')
        const environment = fixtureEnvironment({
            environment: config.environment,
            serve: config.app.serve,
        })
        child = spawn(
            'php',
            ['-c', hostIni, '-S', `127.0.0.1:${port}`, '-t', directory],
            {
                cwd: resolve('.'),
                env: {
                    ...environment,
                    CAPELL_SCREENSHOT_TEST_AUTOLOAD: resolve(
                        'vendor/autoload.php',
                    ),
                },
                stdio: ['ignore', 'pipe', 'pipe'],
            },
        )
        closed = once(child, 'close')
        await new Promise((resolvePromise, rejectPromise) => {
            const timeout = setTimeout(
                () =>
                    rejectPromise(
                        new Error(`HTTP child did not start: ${diagnostics}`),
                    ),
                10000,
            )
            const finish = (error) => {
                clearTimeout(timeout)
                if (error) rejectPromise(error)
                else resolvePromise()
            }
            child.once('error', finish)
            child.once('exit', (code) =>
                finish(new Error(`HTTP child exited ${code}: ${diagnostics}`)),
            )
            child.stderr.on('data', (chunk) => {
                diagnostics += chunk.toString()
                if (
                    diagnostics.includes('Development Server') &&
                    diagnostics.includes('started')
                )
                    finish()
            })
            child.stdout.on('data', (chunk) => {
                diagnostics += chunk.toString()
            })
        })
        const response = await fetch(`http://127.0.0.1:${port}/`, {
            signal: AbortSignal.timeout(10000),
        })
        assert.equal(response.status, 200, diagnostics)
        const actual = await response.json()
        assert.deepEqual(actual.values, [
            environment.DB_DATABASE,
            environment.DB_CONNECTION,
            environment.APP_ENV,
            environment.CAPELL_SCREENSHOT_DISPLAY_ORIGIN,
        ])
        assert.equal(actual.variables_order, 'EGPCS')
        assert.equal(actual.memory, '-1')
        assert.equal(actual.extension_dir, host.extension_dir)
    } finally {
        if (child && child.exitCode === null && child.signalCode === null)
            child.kill('SIGTERM')
        if (closed) await closed
        rmSync(directory, { recursive: true, force: true })
    }
})
