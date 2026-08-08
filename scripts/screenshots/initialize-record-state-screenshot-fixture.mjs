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

    return entries.some((entry) => seededEntryIds.has(entry.id))
}

function hasPageHistorySeed(entries) {
    return entries.some((entry) =>
        ['page-history-timeline', 'page-history-rollback-preview'].includes(
            entry.id,
        ),
    )
}

function hasFrontendPublishedPageSeed(entries) {
    return entries.some((entry) => entry.id === 'frontend-published-page')
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
                env: {
                    ...process.env,
                    ...(config.environment ?? {}),
                    ...(config.serve?.environment ?? {}),
                },
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
                    `Could not initialize the record-state screenshot fixture (exit ${code ?? 'unknown'}).`,
                ),
            )
        })
    })
}

export default async function preCaptureRecordStateFixture({ config, entries }) {
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
        commands.push('Workbench\\App\\Support\\FrontendScreenshotSeed::initialize();')
    }

    if (commands.length === 0) {
        return
    }

    await initializeFixture(config, commands.join(' '))
}
