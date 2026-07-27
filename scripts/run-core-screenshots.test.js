const assert = require('node:assert/strict')
const test = require('node:test')

const { coreRunnerArguments } = require('./run-core-screenshots')

test('scopes screenshot discovery to the Core repository', () => {
    assert.deepEqual(
        coreRunnerArguments([
            '--dry-run',
            '--repo',
            '/tmp/capell-packages',
            '--repo=/tmp/another-repository',
            '--only',
            'core',
        ]),
        [
            '--config',
            'screenshots.config.mjs',
            '--repo',
            require('node:path').resolve(__dirname, '..'),
            '--dry-run',
            '--only',
            'core',
        ],
    )
})

test('always passes the config, since the runner ignores it otherwise', () => {
    const args = coreRunnerArguments([])

    // @capell-app/screenshot-tools is config-file driven. Without --config it
    // falls back to argv and env only, and screenshots.config.mjs's outputRoots
    // write allowlist is silently not enforced.
    assert.deepEqual(args.slice(0, 2), ['--config', 'screenshots.config.mjs'])
})
