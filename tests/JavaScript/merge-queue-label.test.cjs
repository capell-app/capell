const fs = require('fs')
const assert = require('assert').strict
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
;(async () => {
    process.env.QUEUE_TOKEN = 'test'
    process.env.PR_NUMBER = '12'
    process.env.REVIEWED_SHA = 'a'.repeat(40)
    const base = {
        state: 'open',
        draft: false,
        base: { ref: 'main' },
        labels: [{ name: 'ready-to-merge' }],
        head: { sha: 'a'.repeat(40) },
        node_id: 'PR_test',
    }
    async function run(pr) {
        let mutations = 0
        const github = {
            rest: { pulls: { get: async () => ({ data: pr }) } },
            graphql: async (_query, args) => {
                mutations++
                assert.equal(args.sha, 'a'.repeat(40))
                return { enqueuePullRequest: { mergeQueueEntry: { id: 'x' } } }
            },
        }
        try {
            await execute(
                github,
                { ref: 'refs/heads/main', repo: { owner: 'x', repo: 'y' } },
                { notice: () => {} },
            )
            return { mutations }
        } catch (error) {
            return { error: error.message, mutations }
        }
    }
    assert.equal((await run(base)).mutations, 1)
    for (const pr of [
        { ...base, draft: true },
        { ...base, base: { ref: 'other' } },
        { ...base, labels: [] },
        { ...base, labels: [...base.labels, { name: 'awaiting-merge' }] },
        { ...base, head: { sha: 'b'.repeat(40) } },
    ]) {
        const result = await run(pr)
        assert.ok(result.error)
        assert.equal(result.mutations, 0)
    }
    console.log(
        'Eligible label enqueues the event SHA; draft, wrong base, removed label, hold and changed SHA reject.',
    )
})().catch((error) => {
    console.error(error)
    process.exitCode = 1
})
