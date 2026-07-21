const { spawnSync } = require('node:child_process')
const path = require('node:path')

const repositoryRoot = path.resolve(__dirname, '..')

function run(command, args) {
    const result = spawnSync(command, args, {
        cwd: repositoryRoot,
        env: process.env,
        stdio: 'inherit',
    })

    return result.status ?? 1
}

const checks = [
    ['npm', ['run', 'docs:screenshot-coverage']],
    ['npm', ['run', 'docs:filament-action-screenshots']],
]

if (process.env.CAPELL_REPO || process.env.CAPELL_PACKAGES_REPO) {
    checks.push(['npm', ['run', 'screenshots:check']])
}

for (const [command, args] of checks) {
    const status = run(command, args)

    if (status !== 0) {
        process.exitCode = status
        break
    }
}
