import { spawn } from 'node:child_process'

function hasRecordStateFixture(entries) {
    return entries.some((entry) =>
        String(entry.url ?? '').startsWith('/screenshot-fixtures/record-states/'),
    )
}

function initializeFixture(config) {
    return new Promise((resolvePromise, rejectPromise) => {
        const child = spawn(
            'php',
            [
                '-d',
                'memory_limit=-1',
                'vendor/bin/testbench',
                'tinker',
                '--execute',
                'Workbench\\App\\Support\\RecordStateScreenshotFixture::initialize();',
            ],
            {
                cwd: config.appPath,
                env: {
                    ...process.env,
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
    if (!hasRecordStateFixture(entries)) {
        return
    }

    await initializeFixture(config)
}
