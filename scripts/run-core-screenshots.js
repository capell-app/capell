const { spawnSync } = require('node:child_process')
const path = require('node:path')

const repositoryRoot = path.resolve(__dirname, '..')
// The declared @capell-app/screenshot-tools dependency, not a sibling-directory
// guess. Its package exports map only exposes './config', so the supported
// entry point is the bin — which is a two-line shim around src/cli.mjs.
const declaredRunnerCli = path.join(
    repositoryRoot,
    'node_modules',
    '@capell-app',
    'screenshot-tools',
    'bin',
    'capell-screenshots.js',
)

function runnerCliPath() {
    if (process.env.CAPELL_SCREENSHOT_RUNNER_PATH) {
        return path.resolve(process.env.CAPELL_SCREENSHOT_RUNNER_PATH, 'src/cli.mjs')
    }

    return declaredRunnerCli
}

function coreRunnerArguments(args) {
    if (args[0] === 'install-browser') {
        return ['install-browser']
    }

    const forwardedArguments = []

    for (let index = 0; index < args.length; index += 1) {
        if (args[index] === '--repo') {
            index += 1

            continue
        }

        if (args[index].startsWith('--repo=')) {
            continue
        }

        forwardedArguments.push(args[index])
    }

    return [
        '--config',
        'screenshots.config.mjs',
        '--repo',
        repositoryRoot,
        ...forwardedArguments,
    ]
}

function run() {
    const result = spawnSync(
        process.execPath,
        [runnerCliPath(), ...coreRunnerArguments(process.argv.slice(2))],
        {
            cwd: repositoryRoot,
            env: process.env,
            stdio: 'inherit',
        },
    )

    if (result.error) {
        console.error(result.error.message)
    }

    process.exitCode = result.status ?? 1
}

if (require.main === module) {
    run()
}

module.exports = { coreRunnerArguments, runnerCliPath }
