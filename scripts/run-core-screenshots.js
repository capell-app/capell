const { spawnSync } = require('node:child_process')
const path = require('node:path')

const repositoryRoot = path.resolve(__dirname, '..')
const executable = path.join(
    repositoryRoot,
    'node_modules',
    '.bin',
    process.platform === 'win32'
        ? 'capell-screenshots.cmd'
        : 'capell-screenshots',
)
const result = spawnSync(
    executable,
    ['--config', 'screenshots.config.mjs', ...process.argv.slice(2)],
    {
        cwd: repositoryRoot,
        env: process.env,
        stdio: 'inherit',
    },
)

process.exitCode = result.status ?? 1
