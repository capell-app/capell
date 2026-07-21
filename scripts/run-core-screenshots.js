const { spawnSync } = require('node:child_process')
const path = require('node:path')

const repositoryRoot = path.resolve(__dirname, '..')
const runnerRoot = path.resolve(
    process.env.CAPELL_SCREENSHOT_RUNNER_PATH ??
        path.join(repositoryRoot, '..', 'capell-screenshot-runner'),
)
const runnerCli = path.join(runnerRoot, 'src', 'cli.mjs')
const result = spawnSync(
    process.execPath,
    [runnerCli, ...process.argv.slice(2)],
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
