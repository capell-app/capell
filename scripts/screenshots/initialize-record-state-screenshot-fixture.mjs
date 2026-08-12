import { spawn } from 'node:child_process'

function hasRecordStateSeed(entries) {
    const seededEntryIds = new Set([
        'admin-pages-list',
        'admin-layouts-list',
        'admin-media-list',
        'admin-media-edit-focal-point',
        'admin-media-edit-localized-metadata',
        'admin-page-layout-select-record-states',
    ])

    return entries.some(
        (entry) =>
            seededEntryIds.has(entry.id) ||
            entry.url?.startsWith('/screenshot-fixtures/record-states/'),
    )
}

function hasPageHistorySeed(entries) {
    return entries.some((entry) =>
        ['page-history-timeline', 'page-history-rollback-preview'].includes(
            entry.id,
        ),
    )
}

function hasFrontendPublishedPageSeed(entries) {
    return entries.some((entry) =>
        entry.id.startsWith('frontend-published-page'),
    )
}

function frontendSeedCommand(frontendOrigin) {
    const origin = new URL(frontendOrigin)
    const localHostnames = new Set(['127.0.0.1', '::1', '[::1]', 'localhost'])

    if (!localHostnames.has(origin.hostname)) {
        throw new Error(
            `Frontend screenshot fixtures require a local origin, received ${origin.origin}.`,
        )
    }

    return `Workbench\\App\\Support\\FrontendScreenshotSeed::initialize(${JSON.stringify(origin.origin)});`
}

export function commandsForEntries(entries, frontendOrigin = null) {
    const commands = []

    if (hasRecordStateSeed(entries)) {
        commands.push(
            'Workbench\\App\\Support\\RecordStateScreenshotFixture::initialize();',
        )
    }

    if (hasPageHistorySeed(entries)) {
        commands.push('Workbench\\App\\Support\\PageHistoryFixture::editUrl();')
    }

    if (hasFrontendPublishedPageSeed(entries)) {
        commands.push(frontendSeedCommand(frontendOrigin))
    }

    return commands
}

export function fixtureEnvironment(config, processEnvironment = process.env) {
    return {
        ...processEnvironment,
        ...(config.environment ?? {}),
        ...(config.serve?.environment ?? {}),
    }
}

function initializeFixture(config, command) {
    return new Promise((resolvePromise, rejectPromise) => {
        const child = spawn(
            'php',
            [
                '-d',
                'memory_limit=-1',
                'vendor/bin/testbench',
                'tinker',
                '--execute',
                command,
            ],
            {
                cwd: config.appPath,
                env: fixtureEnvironment(config),
                stdio: 'inherit',
            },
        )

        child.on('error', rejectPromise)
        child.on('close', (code) => {
            if (code === 0) {
                resolvePromise()

                return
            }

            rejectPromise(
                new Error(
                    `Could not initialize the selected screenshot fixtures (exit ${code ?? 'unknown'}).`,
                ),
            )
        })
    })
}

export default async function preCaptureRecordStateFixture({
    config,
    entries,
}) {
    const commands = commandsForEntries(entries, config.frontendUrl)

    if (commands.length === 0) {
        return
    }

    await initializeFixture(config, commands.join(' '))
}
