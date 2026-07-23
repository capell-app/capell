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
            '--repo',
            require('node:path').resolve(__dirname, '..'),
            '--dry-run',
            '--only',
            'core',
        ],
    )
})
