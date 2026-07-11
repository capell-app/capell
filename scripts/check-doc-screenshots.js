const { spawnSync } = require('child_process')
const fs = require('fs')
const path = require('path')

const root = path.resolve(__dirname, '..')

function run(command, args, options = {}) {
    const result = spawnSync(command, args, {
        cwd: root,
        env: {
            ...process.env,
            ...options.env,
        },
        stdio: 'inherit',
    })

    return result.status ?? 1
}

function runnerPath() {
    const configuredPath = process.env.CAPELL_SCREENSHOT_RUNNER_PATH

    if (configuredPath) {
        return path.resolve(configuredPath)
    }

    return path.resolve(root, '..', 'capell-screenshot-runner')
}

const coverageStatus = run('npm', ['run', 'docs:screenshot-coverage'])
const filamentActionStatus = run('npm', [
    'run',
    'docs:filament-action-screenshots',
])

function main() {
    if (coverageStatus !== 0) {
        return coverageStatus
    }

    if (filamentActionStatus !== 0) {
        return filamentActionStatus
    }

    const resolvedRunnerPath = runnerPath()
    const runnerCliPath = path.join(resolvedRunnerPath, 'src', 'cli.mjs')
    const runnerRequired = process.env.CAPELL_SCREENSHOT_REQUIRED === 'true'

    if (!fs.existsSync(runnerCliPath)) {
        if (runnerRequired) {
            console.error(
                `Screenshot runner not found at ${resolvedRunnerPath}.`,
            )

            return 1
        }

        console.warn(
            `Screenshot runner not found at ${resolvedRunnerPath}; skipping manifest dry-run. Set CAPELL_SCREENSHOT_REQUIRED=true to fail when the runner is unavailable.`,
        )

        return 0
    }

    return run('npm', ['run', 'screenshots:check', '--', '--skip-build'], {
        env: {
            CAPELL_SCREENSHOT_RUNNER_PATH: resolvedRunnerPath,
        },
    })
}

process.exitCode = main()
