import assert from 'node:assert/strict'
import test from 'node:test'
import heartbeatSource from '../../resources/js/components/content-lock-heartbeat.js'

const PAGE_KEY = 'capell:page-editor:7:12:en'
const OTHER_PAGE_KEY = 'capell:page-editor:7:99:en'
const OTHER_USER_KEY = 'capell:page-editor:8:12:en'
const UNRELATED_KEY = 'capell-ui:sidebar'

function draft(value) {
    return JSON.stringify({
        version: 1,
        data: { title: value },
        savedAt: Date.now(),
        revision: value,
    })
}

class MemoryStorage {
    map = new Map()
    get length() {
        return this.map.size
    }
    key(index) {
        return Array.from(this.map.keys())[index] ?? null
    }
    getItem(key) {
        return this.map.has(key) ? this.map.get(key) : null
    }
    setItem(key, value) {
        this.map.set(key, String(value))
    }
    removeItem(key) {
        this.map.delete(key)
    }
    keys() {
        return Array.from(this.map.keys()).sort()
    }
}

// Runs the component against a stubbed browser. `respond` decides what the
// heartbeat endpoint answers; it may throw to simulate a network failure.
async function mount(
    heartbeatFactory,
    respond = () => ({ ok: true, status: 200 }),
) {
    const storage = new MemoryStorage()
    storage.setItem(PAGE_KEY, draft('current'))
    storage.setItem(`${PAGE_KEY}:page-editor:ctx-1`, draft('session'))
    storage.setItem(`${PAGE_KEY}:emergency:abc`, draft('emergency'))
    storage.setItem(OTHER_PAGE_KEY, draft('other page'))
    storage.setItem(OTHER_USER_KEY, draft('other user'))
    storage.setItem(UNRELATED_KEY, 'collapsed')

    const logoutListeners = {}
    const logoutForm = {
        action: 'https://admin.test/logout',
        addEventListener: (name, cb) => (logoutListeners[name] = cb),
        removeEventListener() {},
        requestSubmit() {},
    }
    const previous = {
        window: globalThis.window,
        document: globalThis.document,
        navigator: globalThis.navigator,
    }
    globalThis.window = {
        localStorage: storage,
        crypto: { randomUUID: () => 'uuid' },
        navigator: { locks: { request: async (_n, cb) => cb() } },
        fetch: async () => respond(),
        setInterval: () => 1,
        clearInterval() {},
        setTimeout: () => 1,
        clearTimeout() {},
        addEventListener() {},
        confirm: () => true,
    }
    Object.defineProperty(globalThis, 'navigator', {
        value: { sendBeacon: () => true },
        configurable: true,
        writable: true,
    })
    globalThis.document = {
        querySelector: () => null,
        forms: [logoutForm],
    }

    const component = Object.assign(
        heartbeatFactory({
            heartbeatUrl: 'https://admin.test/heartbeat',
            releaseUrl: 'https://admin.test/release',
            logoutUrl: 'https://admin.test/logout',
            storageKey: PAGE_KEY,
            localDraftSession: 'page-editor:ctx-1',
        }),
        {
            $nextTick: (cb) => cb(),
            $wire: { data: { title: 'unsaved edit' } },
        },
    )
    component.init()
    // Let the heartbeat promise chain settle.
    await new Promise((resolve) => setTimeout(resolve, 0))
    await new Promise((resolve) => setTimeout(resolve, 0))

    return {
        component,
        storage,
        logoutListeners,
        restore() {
            globalThis.window = previous.window
            globalThis.document = previous.document
            if (previous.navigator === undefined) delete globalThis.navigator
            else
                Object.defineProperty(globalThis, 'navigator', {
                    value: previous.navigator,
                    configurable: true,
                    writable: true,
                })
        },
    }
}

const SURVIVORS = [OTHER_USER_KEY, UNRELATED_KEY].sort()
const ALL_KEYS = [
    PAGE_KEY,
    `${PAGE_KEY}:page-editor:ctx-1`,
    `${PAGE_KEY}:emergency:abc`,
    OTHER_PAGE_KEY,
    OTHER_USER_KEY,
    UNRELATED_KEY,
].sort()

const sources = [['source', heartbeatSource]]
try {
    const published =
        await import('../../publishes/build/js/components/content-lock-heartbeat.js')
    sources.push(['published', published.default])
} catch {
    // The published bundle is checked separately.
}

for (const [label, factory] of sources) {
    test(`${label}: logging out removes every page editor draft kept for the user`, async () => {
        const m = await mount(factory)
        try {
            let submitted = false
            m.component.logoutForm.requestSubmit = () => (submitted = true)
            m.logoutListeners.submit({ preventDefault() {} })
            await new Promise((resolve) => setTimeout(resolve, 0))
            assert.deepEqual(m.storage.keys(), SURVIVORS)
            assert.equal(submitted, true)
            // The logout itself must not write a fresh emergency copy.
            m.component.saveEmergencyDraft()
            await m.component.persistLocalDraft()
            assert.deepEqual(m.storage.keys(), SURVIVORS)
        } finally {
            m.restore()
        }
    })

    for (const status of [401, 419]) {
        test(`${label}: a ${status} heartbeat removes every page editor draft kept for the user`, async () => {
            const m = await mount(factory, () => ({ ok: false, status }))
            try {
                assert.deepEqual(m.storage.keys(), SURVIVORS)
            } finally {
                m.restore()
            }
        })
    }

    test(`${label}: a 403 heartbeat removes only the drafts for this page`, async () => {
        const m = await mount(factory, () => ({ ok: false, status: 403 }))
        try {
            assert.deepEqual(
                m.storage.keys(),
                [OTHER_PAGE_KEY, OTHER_USER_KEY, UNRELATED_KEY].sort(),
            )
        } finally {
            m.restore()
        }
    })

    for (const [name, respond] of [
        ['a 500', () => ({ ok: false, status: 500 })],
        ['a 503', () => ({ ok: false, status: 503 })],
        ['a 409 lock conflict', () => ({ ok: false, status: 409 })],
        [
            'a network failure',
            () => {
                throw new TypeError('Failed to fetch')
            },
        ],
    ]) {
        test(`${label}: ${name} keeps every draft`, async () => {
            const m = await mount(factory, respond)
            try {
                assert.deepEqual(m.storage.keys(), ALL_KEYS)
            } finally {
                m.restore()
            }
        })
    }

    test(`${label}: a rejected save with 419 clears the drafts and one with 500 keeps them`, async () => {
        for (const [status, expected] of [
            [419, SURVIVORS],
            [500, ALL_KEYS],
        ]) {
            const m = await mount(factory)
            try {
                let failCallback
                m.component.$wire.$hook = (_name, handler) =>
                    handler({
                        commit: { calls: [{ method: 'save' }] },
                        succeed() {},
                        fail: (cb) => (failCallback = cb),
                    })
                m.component.init()
                failCallback({ status })
                assert.deepEqual(m.storage.keys(), expected)
            } finally {
                m.restore()
            }
        }
    })
}
