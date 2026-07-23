const { spawnSync } = require('node:child_process')
const path = require('node:path')

const repositoryRoot = path.resolve(__dirname, '..')
const runnerRoot = path.resolve(
    process.env.CAPELL_SCREENSHOT_RUNNER_PATH ??
        path.join(repositoryRoot, '..', 'capell-screenshot-runner'),
)
const runnerCli = path.join(runnerRoot, 'src', 'cli.mjs')

function coreRunnerArguments(args) {
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

    return ['--repo', repositoryRoot, ...forwardedArguments]
}

function run() {
    const result = spawnSync(
        process.execPath,
        [runnerCli, ...coreRunnerArguments(process.argv.slice(2))],
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

module.exports = { coreRunnerArguments }
