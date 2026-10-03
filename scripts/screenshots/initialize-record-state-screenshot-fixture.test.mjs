import assert from 'node:assert/strict'
import test from 'node:test'
import { spawnSync } from 'node:child_process'
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs'
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
