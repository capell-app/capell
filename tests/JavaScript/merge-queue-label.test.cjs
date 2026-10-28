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
    'getOctokit',
    script,
)
const pr = (number, extra = {}) => ({
    number,
    created_at: `2026-01-${String(number).padStart(2, '0')}T00:00:00Z`,
    state: 'open',
    draft: false,
    base: { ref: 'main' },
    labels: [{ name: 'ready-to-merge' }],
    head: { sha: String(number).padStart(40, 'a'), repo: { full_name: 'x/y' } },
    node_id: `PR_${number}`,
    ...extra,
})
async function run(pulls, options = {}) {
    const calls = []
    const app = {
        rest: {
            pulls: {
                updateBranch: async (p) => {
                    calls.push(['update', p.pull_number, p.expected_head_sha])
                },
            },
            issues: {
                removeLabel: async (p) => {
                    calls.push(['failed', p.issue_number])
                },
            },
        },
        graphql: async (_q, v) => {
            calls.push(['enqueue', v.id, v.sha])
            return { enqueuePullRequest: { mergeQueueEntry: { id: 'entry' } } }
        },
    }
    const github = {
        paginate: async () => pulls,
        rest: {
            pulls: {
                list: () => {},
                get: async ({ pull_number }) => ({
                    data:
                        options.fresh ||
                        pulls.find((p) => p.number === pull_number),
                }),
            },
            repos: {
                compareCommitsWithBasehead: async ({ basehead }) => ({
                    data: {
                        behind_by: options.behind?.includes(
                            basehead.split('...')[1],
                        )
                            ? 1
                            : 0,
                    },
                }),
            },
        },
        graphql: async (query, vars) => {
            if (query.includes('QueueState'))
                return {
                    repository: {
                        ref: { associatedPullRequests: { nodes: [] } },
                    },
                    node: {
                        mergeQueue: options.noQueue
                            ? null
                            : {
                                  id: 'queue',
                                  entries: { totalCount: options.busy ? 1 : 0 },
                              },
                        mergeQueueEntry: null,
                    },
                }
            const number = Number(vars.id.slice(3))
            const state = options.states?.[number] || {}
            return {
                node: {
                    mergeStateStatus: state.mergeState || 'CLEAN',
                    mergeQueueEntry: state.queued ? { id: 'entry' } : null,
                    statusCheckRollup:
                        state.rollup === null
                            ? null
                            : { state: state.rollup || 'SUCCESS' },
                    timelineItems: {
                        nodes: state.removed
                            ? [
                                  {
                                      __typename: 'LabeledEvent',
                                      createdAt: '2026-01-01T00:00:00Z',
                                      label: { name: 'ready-to-merge' },
                                  },
                                  {
                                      __typename: 'RemovedFromMergeQueueEvent',
                                      createdAt: '2026-01-02T00:00:00Z',
                                      reason: 'Checks failed',
                                  },
                              ]
                            : [],
                    },
                },
            }
        },
    }
    process.env.QUEUE_TOKEN = 'fixture'
    let error
    try {
        await execute(
            github,
            { repo: { owner: 'x', repo: 'y' } },
            { notice: () => {}, info: () => {}, warning: () => {} },
            () => app,
        )
    } catch (e) {
        error = e
    }
    return { calls, error }
}
;(async () => {
    const a = pr(1),
        b = pr(2)
    assert.deepEqual((await run([b, a])).calls, [
        ['enqueue', 'PR_1', a.head.sha],
    ])
    assert.deepEqual((await run([b, a], { behind: [a.head.sha] })).calls, [
        ['update', 1, a.head.sha],
    ])
    assert.deepEqual(
        (await run([b, a], { states: { 1: { rollup: 'PENDING' } } })).calls,
        [],
    )
    assert.deepEqual(
        (await run([b, a], { states: { 1: { rollup: 'FAILURE' } } })).calls,
        [
            ['failed', 1],
            ['enqueue', 'PR_2', b.head.sha],
        ],
    )
    assert.deepEqual(
        (await run([b, a], { states: { 1: { removed: true } } })).calls,
        [
            ['failed', 1],
            ['enqueue', 'PR_2', b.head.sha],
        ],
    )
    assert.deepEqual(
        (await run([b, a], { states: { 1: { rollup: null } } })).calls,
        [],
    )
    assert.deepEqual(
        (await run([b, a], { states: { 1: { rollup: 'ERROR' } } })).calls,
        [
            ['failed', 1],
            ['enqueue', 'PR_2', b.head.sha],
        ],
    )
    assert.deepEqual(
        (await run([b, a], { states: { 1: { mergeState: 'UNKNOWN' } } })).calls,
        [],
    )
    assert.deepEqual(
        (
            await run([
                pr(1, {
                    head: { ...a.head, repo: { full_name: 'fork/project' } },
                }),
                b,
            ])
        ).calls,
        [['enqueue', 'PR_2', b.head.sha]],
    )
    assert.deepEqual(
        (
            await run([b, a], {
                behind: [a.head.sha],
                states: { 1: { rollup: 'FAILURE' } },
            })
        ).calls,
        [['update', 1, a.head.sha]],
    )
    assert.deepEqual((await run([b, a], { busy: true })).calls, [])
    assert.deepEqual(
        (await run([b, a], { states: { 1: { mergeState: 'DIRTY' } } })).calls,
        [
            ['failed', 1],
            ['enqueue', 'PR_2', b.head.sha],
        ],
    )
    assert.deepEqual(
        (
            await run([a], {
                fresh: { ...a, head: { ...a.head, sha: 'b'.repeat(40) } },
            })
        ).calls,
        [],
    )
    assert.ok((await run([a], { noQueue: true })).error)
    assert.deepEqual(
        (
            await run([
                pr(3, { draft: true }),
                pr(4, {
                    labels: [
                        { name: 'ready-to-merge' },
                        { name: 'awaiting-merge' },
                    ],
                }),
                b,
            ])
        ).calls,
        [['enqueue', 'PR_2', b.head.sha]],
    )
    console.log(
        'Queue processes one oldest eligible PR, updates stale branches, waits, and advances after failures.',
    )
})().catch((error) => {
    console.error(error)
    process.exitCode = 1
})
