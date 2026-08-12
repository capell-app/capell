import assert from 'node:assert/strict'
import test from 'node:test'

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
