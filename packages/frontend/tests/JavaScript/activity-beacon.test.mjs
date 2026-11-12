import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import vm from 'node:vm'

const source = readFileSync(
    new URL('../../resources/js/activity-beacon.js', import.meta.url),
    'utf8',
)

function pageViews({ privacy, navigator = {} } = {}) {
    const beacons = []
    const fetches = []
    const window = { location: { pathname: '/about' } }
    if (privacy !== undefined) window.CapellPrivacy = privacy

    vm.runInNewContext(source, {
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

test('sends nothing while analytics is undecided or refused', () => {
    assert.deepEqual(pageViews({ privacy: { allows: () => null } }), [])
    assert.deepEqual(pageViews({ privacy: { allows: () => false } }), [])
    assert.deepEqual(pageViews({ privacy: {} }), [])
})

test('follows the consent runtime over the browser signals when one is defined', () => {
    assert.equal(
        pageViews({
            privacy: { allows: () => true },
            navigator: { globalPrivacyControl: true },
        }).length,
        1,
    )
})

test('honours Global Privacy Control and Do Not Track without a consent runtime', () => {
    assert.equal(pageViews().length, 1)
    assert.deepEqual(
        pageViews({ navigator: { globalPrivacyControl: true } }),
        [],
    )
    assert.deepEqual(pageViews({ navigator: { doNotTrack: '1' } }), [])
})
