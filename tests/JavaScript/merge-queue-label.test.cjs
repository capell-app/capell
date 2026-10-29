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
    const notices = []
    const warnings = []
    const byNumber = new Map(
        pulls.map((candidate) => [candidate.number, candidate]),
    )
    const app = {
        graphql: async (query, vars) => {
            if (query.includes('query State')) {
                const state = options.states?.[vars.id] || {}
                return {
                    node: {
                        autoMergeRequest: state.autoMerge
                            ? { enabledAt: 'now' }
                            : null,
                        mergeQueueEntry: state.queued ? { id: 'entry' } : null,
                    },
                }
            }
            if (query.includes('disablePullRequestAutoMerge')) {
                calls.push(['disable', vars.id])
                return {}
            }
            if (query.includes('enablePullRequestAutoMerge')) {
                if (options.cleanStatus) {
                    throw new Error('Pull request is in clean status')
                }
                calls.push(['enable', vars.id, vars.sha])
                return {}
            }
            if (query.includes('enqueuePullRequest')) {
                calls.push(['enqueue', vars.id, vars.sha])
                return {}
            }
            throw new Error(`Unexpected query: ${query}`)
        },
    }
    const github = {
        rest: {
            pulls: {
                list: 'list',
                get: async ({ pull_number }) => ({
                    data: byNumber.get(pull_number),
                }),
            },
        },
        paginate: async () =>
            pulls.filter((candidate) => candidate.state === 'open'),
    }
    process.env.QUEUE_TOKEN = 'token'
    process.env.PR_NUMBER =
        options.number === undefined ? '' : String(options.number)
    let error = null
    try {
        await execute(
            github,
            { repo: { owner: 'x', repo: 'y' } },
            {
                notice: (message) => notices.push(message),
                info: () => {},
                warning: (message) => warnings.push(message),
            },
            () => app,
        )
    } catch (e) {
        error = e
    }
    return { calls, error, notices, warnings }
}

;(async () => {
    const a = pr(1)
    assert.deepEqual((await run([a], { number: 1 })).calls, [
        ['enable', 'PR_1', a.head.sha],
    ])
    assert.deepEqual((await run([a], { number: 1, cleanStatus: true })).calls, [
        ['enqueue', 'PR_1', a.head.sha],
    ])
    assert.deepEqual(
        (await run([a], { number: 1, states: { PR_1: { autoMerge: true } } }))
            .calls,
        [],
    )
    assert.deepEqual(
        (await run([a], { number: 1, states: { PR_1: { queued: true } } }))
            .calls,
        [],
    )

    const draft = pr(2, { draft: true })
    assert.deepEqual((await run([draft], { number: 2 })).calls, [])
    assert.deepEqual(
        (
            await run([draft], {
                number: 2,
                states: { PR_2: { autoMerge: true } },
            })
        ).calls,
        [['disable', 'PR_2']],
    )
    const held = pr(3, {
        labels: [{ name: 'ready-to-merge' }, { name: 'awaiting-merge' }],
    })
    assert.deepEqual(
        (
            await run([held], {
                number: 3,
                states: { PR_3: { autoMerge: true } },
            })
        ).calls,
        [['disable', 'PR_3']],
    )
    const unlabelled = pr(4, { labels: [] })
    assert.deepEqual(
        (
            await run([unlabelled], {
                number: 4,
                states: { PR_4: { autoMerge: true } },
            })
        ).calls,
        [['disable', 'PR_4']],
    )
    assert.deepEqual(
        (
            await run([unlabelled], {
                number: 4,
                states: { PR_4: { queued: true } },
            })
        ).calls,
        [],
        'a queued pull request is left for GitHub to dequeue',
    )

    const fork = pr(5, {
        head: { sha: 'b'.repeat(40), repo: { full_name: 'other/y' } },
    })
    const forked = await run([fork], { number: 5 })
    assert.deepEqual(forked.calls, [])
    assert.match(forked.warnings[0], /fork/)

    const sweep = await run([
        pr(6),
        pr(7, { draft: true }),
        pr(8, { labels: [] }),
    ])
    assert.deepEqual(sweep.calls, [['enable', 'PR_6', pr(6).head.sha]])

    const failing = pr(9)
    const broken = await run([failing], { number: 9, cleanStatus: false })
    assert.equal(broken.error, null)
})().catch((error) => {
    console.error(error)
    process.exitCode = 1
})
