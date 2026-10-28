const assert = require('node:assert/strict')
const fs = require('node:fs')
const { spawnSync } = require('node:child_process')

const workflow = fs.readFileSync(
    process.env.QUEUE_WORKFLOW_FILE ||
        '.github/workflows/enqueue-approved-pr.yml',
    'utf8',
)
const match = workflow.match(
    /      - name: Check queue credential\n[\s\S]*?        run: \|\n((?:          .*\n)+)/,
)
assert.ok(match, 'Queue credential preflight must exist')
const script = match[1].replace(/^          /gm, '')
for (const [id, key, expected] of [
    ['', '', 1],
    ['123', '', 1],
    ['', 'test-key', 1],
    ['123', 'test-key', 0],
]) {
    const run = spawnSync('bash', ['-e', '-c', script], {
        encoding: 'utf8',
        env: { ...process.env, QUEUE_APP_ID: id, QUEUE_APP_KEY: key },
    })
    assert.equal(run.status, expected)
    assert.ok(!run.stdout.includes('test-key'))
    if (expected)
        assert.match(
            run.stdout,
            /MERGE_QUEUE_APP_ID and MERGE_QUEUE_APP_PRIVATE_KEY must be configured/,
        )
}
console.log(
    'Missing app credentials fail clearly; a present key is not printed.',
)
