import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import vm from 'node:vm'

const source = readFileSync(
    new URL('../../resources/js/activity-beacon.js', import.meta.url),
    'utf8',
)

// The server substitutes the site's first-party counter setting into this line.
const withCounter = (enabled) =>
    source.replace(
        'const firstPartyCounter = false',
        `const firstPartyCounter = ${enabled}`,
    )

function pageViews({ privacy, navigator = {}, counter = false } = {}) {
    const beacons = []
    const fetches = []
    const window = { location: { pathname: '/about' } }
    if (privacy !== undefined) window.CapellPrivacy = privacy

    vm.runInNewContext(withCounter(counter), {
        window,
        navigator: {
            ...navigator,
            sendBeacon: (url, body) => beacons.push({ url, body }),
        },
        Blob: class Blob {
            constructor(parts, options) {
                this.parts = parts
                this.type = options.type
            }
        },
        fetch: (url) => {
            fetches.push(url)
            return Promise.resolve()
        },
    })

    return [...beacons, ...fetches]
}

const decides = (decision) => ({ allows: () => decision })

test('sends a page view when analytics is granted', () => {
    const sent = pageViews({
        privacy: { allows: (name) => name === 'analytics' },
    })

    assert.equal(sent.length, 1)
    assert.equal(sent[0].url, '/_capell/activity')
    assert.deepEqual(JSON.parse(sent[0].body.parts[0]), {
        type: 'page_view',
        path: '/about',
    })
})

test('sends nothing for a refusal, with or without the counter', () => {
    assert.deepEqual(pageViews({ privacy: decides(false) }), [])
    assert.deepEqual(pageViews({ privacy: decides(false), counter: true }), [])
})

test('counts an undecided visitor only under the first-party counter', () => {
    assert.deepEqual(pageViews({ privacy: decides(null) }), [])
    assert.equal(pageViews({ privacy: decides(null), counter: true }).length, 1)
})

test('sends nothing under Global Privacy Control or Do Not Track, whatever the decision', () => {
    for (const navigator of [
        { globalPrivacyControl: true },
        { doNotTrack: '1' },
    ]) {
        assert.deepEqual(
            pageViews({ privacy: decides(null), counter: true, navigator }),
            [],
        )
        assert.deepEqual(
            pageViews({ privacy: decides(true), counter: true, navigator }),
            [],
        )
    }
})

test('sends nothing when it cannot read consent', () => {
    const throwing = {
        allows: () => {
            throw new Error('Consent store unavailable')
        },
    }

    assert.deepEqual(pageViews({ counter: true }), [])
    assert.deepEqual(pageViews({ privacy: {}, counter: true }), [])
    assert.deepEqual(pageViews({ privacy: throwing, counter: true }), [])
})
