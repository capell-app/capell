const { spawnSync } = require('child_process')
const fs = require('fs')
const path = require('path')

function readFlagValue(argv, index, flag) {
    const current = argv[index]

    if (current === flag) {
        const nextValue = argv[index + 1] ?? ''

        return nextValue.startsWith('-') ? '' : nextValue
    }

    if (current.startsWith(`${flag}=`)) {
        return current.slice(flag.length + 1)
    }

    return null
}

function readOption(argv, flag, fallback = '') {
    for (let index = 0; index < argv.length; index += 1) {
        const value = readFlagValue(argv, index, flag)

        if (value !== null) {
            return value || fallback
        }
    }

    return fallback
}

function collectOptions(argv, flag) {
    const values = []

    for (let index = 0; index < argv.length; index += 1) {
        const value = readFlagValue(argv, index, flag)

        if (value === null) {
            continue
        }

        if (value !== '') {
            values.push(value)
        }

        if (argv[index] === flag) {
            index += 1
        }
    }

    return values
}

function readOnlyFile(filePath) {
    if (!filePath) {
        return []
    }

    return fs
        .readFileSync(filePath, 'utf8')
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter(Boolean)
}

function main() {
    const argv = process.argv.slice(2)
    const configuredRunnerPath = readOption(
        argv,
        '--runner',
        process.env.CAPELL_SCREENSHOT_RUNNER_PATH ?? '',
    )
    const runnerPath = path.resolve(configuredRunnerPath)
    const repoPath = path.resolve(
        readOption(
            argv,
            '--repo',
            process.env.CAPELL_CORE_REPO_PATH ?? process.cwd(),
        ),
    )
    const packagesRepoPath = readOption(
        argv,
        '--packages-repo',
        process.env.CAPELL_PACKAGES_REPO ??
            path.resolve(repoPath, '..', 'capell-packages-4'),
    )
    const appPath = readOption(
        argv,
        '--app',
        process.env.CAPELL_SCREENSHOT_APP_PATH ?? '',
    )
    const only = [
        ...collectOptions(argv, '--only'),
        ...readOnlyFile(readOption(argv, '--only-file')),
    ]

    if (!configuredRunnerPath) {
        console.error(
            'Set CAPELL_SCREENSHOT_RUNNER_PATH or pass --runner to locate capell-screenshot-runner.',
        )
        process.exitCode = 1

        return
    }

    if (!fs.existsSync(path.join(runnerPath, 'src/cli.mjs'))) {
        console.error(`Screenshot runner was not found at ${runnerPath}.`)
        process.exitCode = 1

        return
    }

    const runnerArgs = ['src/cli.mjs', '--repo', repoPath]

    if (packagesRepoPath && fs.existsSync(packagesRepoPath)) {
        runnerArgs.push('--repo', path.resolve(packagesRepoPath))
    }

    if (appPath) {
        runnerArgs.push('--app', path.resolve(appPath))
    }

    for (const packageName of only) {
        runnerArgs.push('--only', packageName)
    }

    if (argv.includes('--dry-run')) {
        runnerArgs.push('--dry-run')
    }

    if (argv.includes('--skip-build')) {
        runnerArgs.push('--skip-build')
    }

    const result = spawnSync('node', runnerArgs, {
        cwd: runnerPath,
        env: {
            ...process.env,
            CAPELL_REPO: repoPath,
            DEBUGBAR_ENABLED: process.env.DEBUGBAR_ENABLED ?? 'false',
        },
        stdio: 'inherit',
    })

    process.exitCode = result.status ?? 1
}

main()
