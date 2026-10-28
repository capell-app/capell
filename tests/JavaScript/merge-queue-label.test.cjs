const fs = require('node:fs')
const assert = require('node:assert/strict')
const text = fs.readFileSync(
    process.env.QUEUE_WORKFLOW_FILE ||
        '.github/workflows/enqueue-approved-pr.yml',
    'utf8',
)
const script = text
    .split('          script: |\n')[1]
    .split('\n')
    .map((line) => (line.startsWith('            ') ? line.slice(12) : line))
    .join('\n')
const execute = new (Object.getPrototypeOf(async function () {}).constructor)(
    'github',
    'context',
    'core',
    script,
)

const pr = (number, date, extra = {}) => ({
    number,
    created_at: date,
    state: 'open',
    draft: false,
    base: { ref: 'main' },
    labels: [{ name: 'ready-to-merge' }],
    head: { sha: String(number).padStart(40, 'a') },
    node_id: `PR_${number}`,
    ...extra,
})
async function run(pulls, options = {}) {
    const attempts = []
    const github = {
        paginate: async () => pulls,
        rest: {
            pulls: {
                list: () => {},
                get: async ({ pull_number }) => ({
                    data:
                        options.fresh ||
                        pulls.find((item) => item.number === pull_number),
                }),
            },
        },
        graphql: async (query, vars) => {
            if (query.includes('query('))
                return {
                    node: {
                        mergeQueue: options.noQueue ? null : { id: 'queue' },
                        mergeQueueEntry: options.queued?.includes(vars.id)
                            ? { id: 'entry' }
                            : null,
                    },
                }
            attempts.push(vars.id)
            assert.equal(
                vars.sha,
                pulls.find((item) => item.node_id === vars.id).head.sha,
            )
            if (vars.id === options.blocked)
                throw new Error('Required checks pending')
            return { enqueuePullRequest: { mergeQueueEntry: { id: 'entry' } } }
        },
    }
    process.env.QUEUE_TOKEN = 'fixture-token'
    let error
    try {
        await execute(
            github,
            { repo: { owner: 'x', repo: 'y' } },
            { notice: () => {}, info: () => {} },
        )
    } catch (caught) {
        error = caught
    }
    return { attempts, error }
}
;(async () => {
    const oldest = pr(1, '2026-01-01T00:00:00Z')
    const newer = pr(2, '2026-02-01T00:00:00Z')
    const held = pr(3, '2025-01-01T00:00:00Z', {
        labels: [{ name: 'ready-to-merge' }, { name: 'awaiting-merge' }],
    })
    const draft = pr(4, '2025-01-01T00:00:00Z', { draft: true })
    assert.deepEqual((await run([newer, held, oldest, draft])).attempts, [
        'PR_1',
        'PR_2',
    ])
    const blocked = await run([newer, oldest], { blocked: 'PR_1' })
    assert.ok(blocked.error)
    assert.deepEqual(blocked.attempts, ['PR_1'])
    assert.deepEqual(
        (await run([newer, oldest], { queued: ['PR_1'] })).attempts,
        ['PR_2'],
    )
    for (const opts of [
        { fresh: { ...oldest, labels: [] } },
        { fresh: { ...oldest, head: { sha: 'b'.repeat(40) } } },
        { noQueue: true },
    ]) {
        const rejected = await run([oldest], opts)
        assert.ok(rejected.error)
        assert.deepEqual(rejected.attempts, [])
    }
    console.log(
        'Existing labelled PRs are queued oldest first; held and draft PRs are excluded, and a blocked oldest PR stops later entries.',
    )
})().catch((error) => {
    console.error(error)
    process.exitCode = 1
})
