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
for (const [token, expected] of [
    ['', 1],
    ['test-token', 0],
]) {
    const run = spawnSync('bash', ['-e', '-c', script], {
        encoding: 'utf8',
        env: { ...process.env, QUEUE_TOKEN: token },
    })
    assert.equal(run.status, expected)
    assert.ok(!run.stdout.includes('test-token'))
    if (!token) assert.match(run.stdout, /MERGE_QUEUE_TOKEN is not configured/)
}
console.log(
    'Missing queue credential fails clearly; a present credential is not printed.',
)
