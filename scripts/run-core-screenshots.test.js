const assert = require('node:assert/strict')
const test = require('node:test')

const { coreRunnerArguments, runnerCliPath } = require('./run-core-screenshots')

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
            '--skip-build',
        ],
    )
})

test('uses the explicit isolated shared runner when configured', () => {
    const previous = process.env.CAPELL_SCREENSHOT_RUNNER_PATH
    process.env.CAPELL_SCREENSHOT_RUNNER_PATH = '/tmp/capell-screenshot-runner'

    assert.equal(runnerCliPath(), '/tmp/capell-screenshot-runner/src/cli.mjs')

    if (previous === undefined) delete process.env.CAPELL_SCREENSHOT_RUNNER_PATH
    else process.env.CAPELL_SCREENSHOT_RUNNER_PATH = previous
})

test('always passes the config, since the runner ignores it otherwise', () => {
    const args = coreRunnerArguments([])

    // @capell-app/screenshot-tools is config-file driven. Without --config it
    // falls back to argv and env only, and screenshots.config.mjs's outputRoots
    // write allowlist is silently not enforced.
    assert.deepEqual(args.slice(0, 2), ['--config', 'screenshots.config.mjs'])
    assert.equal(args.at(-1), '--skip-build')
})

test('screenshot configuration validates and applies its local port', () => {
    const { spawnSync } = require('node:child_process')
    const script = `import('./screenshots.config.mjs').then(({default: c}) => console.log(JSON.stringify([c.urls.frontend, c.app.serve.readyUrl, c.app.serve.args, c.environment.CAPELL_SCREENSHOT_PORT])))`
    const run = (port) => {
        const env = { ...process.env }
        if (port === undefined) delete env.CAPELL_SCREENSHOT_PORT
        else env.CAPELL_SCREENSHOT_PORT = port
        return spawnSync(process.execPath, ['-e', script], {
            cwd: require('node:path').resolve(__dirname, '..'),
            env,
            encoding: 'utf8',
        })
    }
    for (const [port, expected] of [
        [undefined, '8145'],
        ['18147', '18147'],
    ]) {
        const result = run(port)
        assert.equal(result.status, 0, result.stderr)
        const [frontend, ready, args, environment] = JSON.parse(result.stdout)
        assert.equal(frontend, `http://127.0.0.1:${expected}`)
        assert.equal(ready, `${frontend}/__ping`)
        assert.ok(args.includes(`--port=${expected}`))
        assert.equal(environment, expected)
    }
    for (const port of ['0', '1023', '65536', '12abc', '']) {
        const result = run(port)
        assert.notEqual(result.status, 0)
        assert.match(result.stderr, /CAPELL_SCREENSHOT_PORT must be an integer/)
    }
})
