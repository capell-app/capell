const assert = require('node:assert/strict')
const path = require('node:path')
const { test } = require('node:test')
const { pathToFileURL } = require('node:url')

const componentUrl = pathToFileURL(
    path.resolve(
        __dirname,
        '../../..',
        'packages/admin/resources/js/components/content-lock-heartbeat.js',
    ),
).href

const flush = () => new Promise((resolve) => setImmediate(resolve))

test('rechecks the lock after a successful Livewire retry', async () => {
    const originalDocument = global.document
    const originalWindow = global.window
    const originalNavigator = global.navigator
    const attributes = {}
    const responses = [
        { ok: false, status: 403 },
        { ok: true, status: 200 },
    ]
    const fetchCalls = []
    let commitHook

    const form = {
        addEventListener() {},
        toggleAttribute(name, value) {
            attributes[name] = value
        },
        setAttribute(name, value) {
            attributes[name] = value
        },
        querySelectorAll() {
            return []
        },
    }

    global.document = {
        forms: [],
        querySelector() {
            return form
        },
    }
    global.window = {
        addEventListener() {},
        clearInterval() {},
        fetch(url) {
            fetchCalls.push(url)

            return Promise.resolve(responses.shift())
        },
        setInterval() {
            return 1
        },
    }
    global.navigator = {}

    try {
        const { default: contentLockHeartbeat } = await import(componentUrl)
        const component = contentLockHeartbeat({ heartbeatUrl: '/heartbeat' })

        component.$nextTick = (callback) => callback()
        component.$wire = {
            $hook(name, callback) {
                assert.equal(name, 'commit')
                commitHook = callback
            },
        }

        component.init()
        await flush()

        assert.equal(component.permissionBlocked, true)
        assert.equal(component.readOnly, true)
        assert.equal(attributes['data-capell-content-lock-read-only'], true)

        commitHook({
            commit: { calls: [{ method: 'mountAction' }] },
            succeed(callback) {
                callback()
            },
        })
        await flush()

        assert.deepEqual(fetchCalls, ['/heartbeat', '/heartbeat'])
        assert.equal(component.permissionBlocked, false)
        assert.equal(component.readOnly, false)
        assert.equal(attributes['data-capell-content-lock-read-only'], false)
    } finally {
        if (originalDocument === undefined) {
            delete global.document
        } else {
            global.document = originalDocument
        }

        if (originalWindow === undefined) {
            delete global.window
        } else {
            global.window = originalWindow
        }

        if (originalNavigator === undefined) {
            delete global.navigator
        } else {
            global.navigator = originalNavigator
        }
    }
})

test('does not let an older heartbeat response overwrite a newer result', async () => {
    const originalDocument = global.document
    const originalWindow = global.window
    const originalNavigator = global.navigator
    let resolveFirst
    let resolveSecond
    let fetchCount = 0

    global.document = {
        forms: [],
    }
    global.window = {
        addEventListener() {},
        fetch() {
            fetchCount += 1

            return new Promise((resolve) => {
                if (fetchCount === 1) {
                    resolveFirst = resolve
                } else {
                    resolveSecond = resolve
                }
            })
        },
        setInterval() {
            return 1
        },
    }
    global.navigator = {}

    try {
        const { default: contentLockHeartbeat } = await import(componentUrl)
        const component = contentLockHeartbeat({ heartbeatUrl: '/heartbeat' })

        component.$nextTick = () => {}
        component.init()
        component.heartbeat()

        resolveSecond({ ok: true, status: 200 })
        await flush()
        resolveFirst({ ok: false, status: 403 })
        await flush()

        assert.equal(component.permissionBlocked, false)
        assert.equal(component.readOnly, false)
    } finally {
        if (originalDocument === undefined) {
            delete global.document
        } else {
            global.document = originalDocument
        }

        if (originalWindow === undefined) {
            delete global.window
        } else {
            global.window = originalWindow
        }

        if (originalNavigator === undefined) {
            delete global.navigator
        } else {
            global.navigator = originalNavigator
        }
    }
})

test('keeps an earlier browser recovery while writing a new session and asks before replacement', async () => {
    const originalWindow = global.window
    const originalNavigator = global.navigator
    global.navigator = {
        locks: { request: async (name, callback) => callback() },
    }
    const entries = new Map()
    const storage = {
        get length() {
            return entries.size
        },
        key(index) {
            return Array.from(entries.keys())[index] ?? null
        },
        getItem(key) {
            return entries.get(key) ?? null
        },
        setItem(key, value) {
            entries.set(key, value)
        },
        removeItem(key) {
            entries.delete(key)
        },
    }
    const key = 'capell:page-editor:1:2:en'
    const previous = JSON.stringify({
        version: 1,
        data: { name: 'old' },
        savedAt: Date.now(),
    })
    storage.setItem(key, previous)
    let confirmed = false
    global.window = {
        localStorage: storage,
        confirm: () => confirmed,
        clearTimeout() {},
        navigator: { locks: { request: async (name, callback) => callback() } },
        crypto: { randomUUID: () => 'revision' },
    }
    try {
        const { default: contentLockHeartbeat } = await import(componentUrl)
        const component = contentLockHeartbeat({
            storageKey: key,
            localDraftSession: 'session-a',
            localDraftRestoreConfirm: 'Replace?',
        })
        component.$wire = {
            data: { name: 'saved' },
            $set(name, value) {
                this[name] = value
            },
        }
        component.$nextTick = () => {}
        component.initialDataHash = component.dataHash()
        component.localDraft = component.readStoredLocalDraft()
        component.localDraftAvailable = true
        component.$wire.data = { name: 'current' }
        await component.persistLocalDraft()
        assert.equal(storage.getItem(key), previous)
        assert.deepEqual(JSON.parse(storage.getItem(`${key}:session-a`)).data, {
            name: 'current',
        })
        await component.discardLocalDraft()
        assert.equal(storage.getItem(key), previous)
        await component.restoreLocalDraft()
        assert.deepEqual(component.$wire.data, { name: 'current' })
        confirmed = true
        await component.restoreLocalDraft()
        assert.deepEqual(component.$wire.data, { name: 'old' })
        assert.equal(storage.getItem(key), previous)
        assert.deepEqual(JSON.parse(storage.getItem(`${key}:session-a`)).data, {
            name: 'current',
        })
    } finally {
        if (originalWindow === undefined) delete global.window
        else global.window = originalWindow
        if (originalNavigator === undefined) delete global.navigator
        else global.navigator = originalNavigator
    }
})

test('does not remove a changed recovery or write without browser coordination', async () => {
    const originalWindow = global.window
    const entries = new Map()
    const storage = {
        get length() {
            return entries.size
        },
        key(index) {
            return [...entries.keys()][index] ?? null
        },
        getItem(key) {
            return entries.get(key) ?? null
        },
        setItem(key, value) {
            entries.set(key, value)
        },
        removeItem(key) {
            entries.delete(key)
        },
    }
    const key = 'capell:page-editor:1:2:en'
    const snapshot = JSON.stringify({
        version: 1,
        data: { name: 'old' },
        savedAt: Date.now(),
        revision: 'a',
    })
    storage.setItem(key, snapshot)
    global.window = {
        localStorage: storage,
        confirm: () => true,
        navigator: { locks: { request: async (name, callback) => callback() } },
        crypto: { randomUUID: () => 'c' },
    }
    try {
        const { default: contentLockHeartbeat } = await import(componentUrl)
        const component = contentLockHeartbeat({
            storageKey: key,
            localDraftSession: 'new',
        })
        component.localDraft = component.readStoredLocalDraft()
        component.localDraftAvailable = true
        const newer = JSON.stringify({
            version: 1,
            data: { name: 'old' },
            savedAt: Date.now(),
            revision: 'b',
        })
        storage.setItem(key, newer)
        await component.discardLocalDraft()
        assert.equal(storage.getItem(key), newer)
        assert.equal(component.localDraftAvailable, true)
        assert.equal(component.localDraftChanged, true)
        assert.equal(component.recoveredDraftRaw, newer)
        delete global.window.navigator.locks
        component.$wire = { data: { name: 'new' } }
        component.initialDataHash = '{}'
        await component.persistLocalDraft()
        assert.equal(component.localDraftUnavailable, true)
        assert.equal(storage.getItem(`${key}:new`), null)
        assert.equal(storage.getItem(key), newer)
    } finally {
        if (originalWindow === undefined) delete global.window
        else global.window = originalWindow
    }
})

test('retains browser recovery if the form transfer fails', async () => {
    const originalWindow = global.window
    const key = 'capell:page-editor:1:2:en'
    const raw = JSON.stringify({
        version: 1,
        data: { name: 'old' },
        savedAt: Date.now(),
    })
    const values = new Map([[key, raw]])
    const localStorage = {
        get length() {
            return values.size
        },
        key(index) {
            return [...values.keys()][index] ?? null
        },
        getItem(name) {
            return values.get(name) ?? null
        },
        setItem(name, value) {
            values.set(name, value)
        },
        removeItem(name) {
            values.delete(name)
        },
    }
    global.window = {
        localStorage,
        navigator: { locks: { request: async (name, callback) => callback() } },
    }
    try {
        const { default: contentLockHeartbeat } = await import(componentUrl)
        const component = contentLockHeartbeat({
            storageKey: key,
            localDraftSession: 'new',
        })
        component.$wire = {
            data: { name: 'saved' },
            $set() {
                throw new Error('transfer failed')
            },
        }
        component.initialDataHash = component.dataHash()
        component.localDraft = component.readStoredLocalDraft()
        await assert.rejects(component.restoreLocalDraft(), /transfer failed/)
        assert.equal(localStorage.getItem(key), raw)
    } finally {
        if (originalWindow === undefined) delete global.window
        else global.window = originalWindow
    }
})

test('reports browser recovery unavailable when storage access throws', async () => {
    const originalWindow = global.window
    global.window = {
        navigator: { locks: { request: async (name, callback) => callback() } },
        get localStorage() {
            throw new Error('SecurityError')
        },
    }
    try {
        const { default: contentLockHeartbeat } = await import(componentUrl)
        const component = contentLockHeartbeat({
            storageKey: 'private',
            localDraftSession: 'new',
        })
        component.$wire = { data: { name: 'changed' } }
        component.initialDataHash = '{}'
        await component.persistLocalDraft()
        assert.equal(component.localDraftUnavailable, true)
        assert.equal(component.readStoredLocalDraft(), null)
    } finally {
        if (originalWindow === undefined) delete global.window
        else global.window = originalWindow
    }
})

test('changes browser recovery identity when the server forks a conflicting session', async () => {
    const originalWindow = global.window
    const entries = new Map()
    const storage = {
        get length() {
            return entries.size
        },
        key(index) {
            return [...entries.keys()][index] ?? null
        },
        getItem(key) {
            return entries.get(key) ?? null
        },
        setItem(key, value) {
            entries.set(key, value)
        },
        removeItem(key) {
            entries.delete(key)
        },
    }
    const base = 'capell:page-editor:1:2:en'
    const original = JSON.stringify({
        version: 1,
        data: { name: 'old' },
        savedAt: Date.now(),
        revision: 'old',
    })
    storage.setItem(`${base}:old`, original)
    global.window = {
        localStorage: storage,
        navigator: { locks: { request: async (name, callback) => callback() } },
        crypto: { randomUUID: () => 'new' },
        clearTimeout() {},
        setTimeout() {
            return 1
        },
    }
    try {
        const { default: contentLockHeartbeat } = await import(componentUrl)
        const component = contentLockHeartbeat({
            storageKey: base,
            localDraftSession: 'old',
        })
        component.$wire = { data: { name: 'new' } }
        component.initialDataHash = '{}'
        component.changeDraftSession('new')
        await component.persistLocalDraft()
        assert.equal(storage.getItem(`${base}:old`), original)
        assert.deepEqual(JSON.parse(storage.getItem(`${base}:new`)).data, {
            name: 'new',
        })
        assert.deepEqual(component.localDraft, { name: 'old' })
    } finally {
        if (originalWindow === undefined) delete global.window
        else global.window = originalWindow
    }
})

test('writes an immutable emergency snapshot synchronously when unloading with a pending edit', async () => {
    const originalWindow = global.window
    const entries = new Map()
    const storage = {
        getItem(key) {
            return entries.get(key) ?? null
        },
        setItem(key, value) {
            entries.set(key, value)
        },
    }
    global.window = {
        localStorage: storage,
        crypto: { randomUUID: () => 'unique' },
        clearTimeout() {},
        navigator: { locks: { request: () => new Promise(() => {}) } },
    }
    try {
        const { default: contentLockHeartbeat } = await import(componentUrl)
        const component = contentLockHeartbeat({
            storageKey: 'editor',
            localDraftSession: 'tab',
        })
        component.$wire = { data: { name: 'latest' } }
        component.initialDataHash = JSON.stringify({ name: 'saved' })
        component.localDraftTimer = 1
        component.release(true)
        assert.deepEqual(
            JSON.parse(storage.getItem('editor:emergency:unique')).data,
            { name: 'latest' },
        )
    } finally {
        if (originalWindow === undefined) delete global.window
        else global.window = originalWindow
    }
})

test('writes an emergency snapshot when the debounce fired but its lock is still pending', async () => {
    const originalWindow = global.window
    const entries = new Map()
    const storage = {
        getItem(key) {
            return entries.get(key) ?? null
        },
        setItem(key, value) {
            entries.set(key, value)
        },
    }
    global.window = {
        localStorage: storage,
        crypto: { randomUUID: () => 'pending' },
        clearTimeout() {},
        navigator: { locks: { request: () => new Promise(() => {}) } },
    }
    try {
        const { default: contentLockHeartbeat } = await import(componentUrl)
        const component = contentLockHeartbeat({
            storageKey: 'editor',
            localDraftSession: 'tab',
        })
        component.$wire = { data: { name: 'latest' } }
        component.initialDataHash = JSON.stringify({ name: 'saved' })
        component.lastDurableDataHash = component.initialDataHash
        component.persistLocalDraft()
        component.localDraftTimer = null
        component.release(true)
        assert.deepEqual(
            JSON.parse(storage.getItem('editor:emergency:pending')).data,
            { name: 'latest' },
        )
    } finally {
        if (originalWindow === undefined) delete global.window
        else global.window = originalWindow
    }
})

test('does not create an emergency duplicate when the latest edit is already durable', async () => {
    const originalWindow = global.window
    const entries = new Map()
    const storage = {
        getItem(key) {
            return entries.get(key) ?? null
        },
        setItem(key, value) {
            entries.set(key, value)
        },
        removeItem(key) {
            entries.delete(key)
        },
    }
    global.window = {
        localStorage: storage,
        crypto: { randomUUID: () => 'durable' },
        clearTimeout() {},
        navigator: { locks: { request: async (name, callback) => callback() } },
    }
    try {
        const { default: contentLockHeartbeat } = await import(componentUrl)
        const component = contentLockHeartbeat({
            storageKey: 'editor',
            localDraftSession: 'tab',
        })
        component.$wire = { data: { name: 'latest' } }
        component.initialDataHash = JSON.stringify({ name: 'saved' })
        component.lastDurableDataHash = component.initialDataHash
        await component.persistLocalDraft()
        component.release(true)
        assert.equal(storage.getItem('editor:emergency:durable'), null)
        assert.deepEqual(JSON.parse(storage.getItem('editor:tab')).data, {
            name: 'latest',
        })
    } finally {
        if (originalWindow === undefined) delete global.window
        else global.window = originalWindow
    }
})

test('preserves an edit made after a save was submitted but before its response', async () => {
    const originalWindow = global.window
    const entries = new Map()
    const storage = {
        getItem(key) {
            return entries.get(key) ?? null
        },
        setItem(key, value) {
            entries.set(key, value)
        },
        removeItem(key) {
            entries.delete(key)
        },
    }
    global.window = {
        localStorage: storage,
        crypto: { randomUUID: () => 'inflight' },
        clearTimeout() {},
        setTimeout() {
            return 1
        },
        navigator: { locks: { request: () => new Promise(() => {}) } },
    }
    try {
        const { default: contentLockHeartbeat } = await import(componentUrl)
        const component = contentLockHeartbeat({
            storageKey: 'editor',
            localDraftSession: 'tab',
        })
        component.$wire = { data: { name: 'edited during request' } }
        component.initialDataHash = JSON.stringify({ name: 'before save' })
        component.lastDurableDataHash = component.initialDataHash
        component.markEditorSaved({ name: 'submitted' })
        component.release(true)
        assert.deepEqual(
            JSON.parse(storage.getItem('editor:emergency:inflight')).data,
            { name: 'edited during request' },
        )
    } finally {
        if (originalWindow === undefined) delete global.window
        else global.window = originalWindow
    }
})

test('does not clear recovery when an older server does not provide acknowledged state', async () => {
    const originalWindow = global.window
    const entries = new Map([
        [
            'editor:tab',
            JSON.stringify({
                version: 1,
                data: { name: 'latest' },
                savedAt: Date.now(),
            }),
        ],
    ])
    const storage = {
        getItem(key) {
            return entries.get(key) ?? null
        },
        setItem(key, value) {
            entries.set(key, value)
        },
        removeItem(key) {
            entries.delete(key)
        },
    }
    global.window = {
        localStorage: storage,
        crypto: { randomUUID: () => 'legacy' },
        clearTimeout() {},
        setTimeout() {
            return 1
        },
        navigator: { locks: { request: async (name, callback) => callback() } },
    }
    try {
        const { default: contentLockHeartbeat } = await import(componentUrl)
        const component = contentLockHeartbeat({
            storageKey: 'editor',
            localDraftSession: 'tab',
        })
        component.$wire = { data: { name: 'latest' } }
        component.initialDataHash = JSON.stringify({ name: 'saved' })
        await component.markEditorSaved()
        assert.notEqual(storage.getItem('editor:tab'), null)
        assert.notEqual(component.localDraftTimer, null)
    } finally {
        if (originalWindow === undefined) delete global.window
        else global.window = originalWindow
    }
})

test('removes an observed obsolete session draft after its newer form is saved', async () => {
    const originalWindow = global.window
    const originalDocument = global.document
    const old = JSON.stringify({
        version: 1,
        data: { name: 'A' },
        savedAt: Date.now(),
        revision: 'old',
    })
    const entries = new Map([['editor:tab', old]])
    const storage = {
        get length() {
            return entries.size
        },
        key(index) {
            return [...entries.keys()][index] ?? null
        },
        getItem(key) {
            return entries.get(key) ?? null
        },
        setItem(key, value) {
            entries.set(key, value)
        },
        removeItem(key) {
            entries.delete(key)
        },
    }
    global.window = {
        localStorage: storage,
        crypto: { randomUUID: () => 'id' },
        addEventListener() {},
        clearTimeout() {},
        setTimeout() {
            return 1
        },
        navigator: { locks: { request: async (name, callback) => callback() } },
    }
    global.document = {
        querySelector() {
            return null
        },
        forms: [],
    }
    try {
        const { default: contentLockHeartbeat } = await import(componentUrl)
        const component = contentLockHeartbeat({
            storageKey: 'editor',
            localDraftSession: 'tab',
        })
        let hook
        component.$wire = {
            data: { name: 'saved' },
            $hook(name, callback) {
                hook = callback
            },
        }
        component.$nextTick = (callback) => callback()
        component.init()
        component.$wire.data = { name: 'B' }
        hook({
            commit: { calls: [{ method: 'save' }] },
            succeed() {},
            fail() {},
        })
        await component.markEditorSaved({ name: 'B' })
        assert.equal(storage.getItem('editor:tab'), null)
    } finally {
        if (originalWindow === undefined) delete global.window
        else global.window = originalWindow
        if (originalDocument === undefined) delete global.document
        else global.document = originalDocument
    }
})

test('preserves a newer session draft written after the save was submitted', async () => {
    const originalWindow = global.window
    const originalDocument = global.document
    const old = JSON.stringify({
        version: 1,
        data: { name: 'A' },
        savedAt: Date.now(),
        revision: 'old',
    })
    const newer = JSON.stringify({
        version: 1,
        data: { name: 'C' },
        savedAt: Date.now(),
        revision: 'new',
    })
    const entries = new Map([['editor:tab', old]])
    const storage = {
        get length() {
            return entries.size
        },
        key(index) {
            return [...entries.keys()][index] ?? null
        },
        getItem(key) {
            return entries.get(key) ?? null
        },
        setItem(key, value) {
            entries.set(key, value)
        },
        removeItem(key) {
            entries.delete(key)
        },
    }
    global.window = {
        localStorage: storage,
        crypto: { randomUUID: () => 'id' },
        addEventListener() {},
        clearTimeout() {},
        setTimeout() {
            return 1
        },
        navigator: { locks: { request: async (name, callback) => callback() } },
    }
    global.document = {
        querySelector() {
            return null
        },
        forms: [],
    }
    try {
        const { default: contentLockHeartbeat } = await import(componentUrl)
        const component = contentLockHeartbeat({
            storageKey: 'editor',
            localDraftSession: 'tab',
        })
        let hook
        component.$wire = {
            data: { name: 'saved' },
            $hook(name, callback) {
                hook = callback
            },
        }
        component.$nextTick = (callback) => callback()
        component.init()
        component.$wire.data = { name: 'B' }
        hook({
            commit: { calls: [{ method: 'save' }] },
            succeed() {},
            fail() {},
        })
        storage.setItem('editor:tab', newer)
        await component.markEditorSaved({ name: 'B' })
        assert.equal(storage.getItem('editor:tab'), newer)
    } finally {
        if (originalWindow === undefined) delete global.window
        else global.window = originalWindow
        if (originalDocument === undefined) delete global.document
        else global.document = originalDocument
    }
})

test('tracks Filament draft action mount and separate modal execution without retaining cancelled submissions', async () => {
    const originalWindow = global.window
    const originalDocument = global.document
    const entries = new Map([
        [
            'editor:tab',
            JSON.stringify({
                version: 1,
                data: { name: 'A' },
                savedAt: Date.now(),
                revision: 'old',
            }),
        ],
    ])
    const storage = {
        get length() {
            return entries.size
        },
        key(index) {
            return [...entries.keys()][index] ?? null
        },
        getItem(key) {
            return entries.get(key) ?? null
        },
        setItem(key, value) {
            entries.set(key, value)
        },
        removeItem(key) {
            entries.delete(key)
        },
    }
    global.window = {
        localStorage: storage,
        crypto: { randomUUID: () => 'id' },
        addEventListener() {},
        clearTimeout() {},
        setTimeout() {
            return 1
        },
        navigator: { locks: { request: async (name, callback) => callback() } },
    }
    global.document = {
        querySelector() {
            return null
        },
        forms: [],
    }
    try {
        const { default: contentLockHeartbeat } = await import(componentUrl)
        const component = contentLockHeartbeat({
            storageKey: 'editor',
            localDraftSession: 'tab',
        })
        let hook
        component.$wire = {
            data: { name: 'saved' },
            mountedActions: [],
            $hook(name, callback) {
                hook = callback
            },
        }
        component.$nextTick = (callback) => callback()
        component.init()
        component.$wire.data = { name: 'B' }
        let succeeded
        let failed
        hook({
            commit: {
                calls: [{ method: 'mountAction', params: ['saveAsDraft'] }],
            },
            succeed(callback) {
                succeeded = callback
            },
            fail(callback) {
                failed = callback
            },
        })
        assert.equal(component.saveSubmissions.length, 1)
        succeeded()
        assert.equal(component.saveSubmissions.length, 0)
        component.$wire.mountedActions = [{ name: 'saveAsDraft' }]
        hook({
            commit: { calls: [{ method: 'callMountedAction', params: [] }] },
            succeed(callback) {
                succeeded = callback
            },
            fail(callback) {
                failed = callback
            },
        })
        assert.equal(component.saveSubmissions.length, 1)
        failed()
        assert.equal(component.saveSubmissions.length, 0)
        hook({
            commit: { calls: [{ method: 'callMountedAction', params: [] }] },
            succeed(callback) {
                succeeded = callback
            },
            fail(callback) {
                failed = callback
            },
        })
        await component.markEditorSaved({ name: 'B' })
        assert.equal(storage.getItem('editor:tab'), null)
        succeeded()
        assert.equal(component.saveSubmissions.length, 0)
        component.$wire.mountedActions = [{ name: 'delete' }]
        hook({
            commit: { calls: [{ method: 'callMountedAction', params: [] }] },
            succeed() {},
            fail() {},
        })
        assert.equal(component.saveSubmissions.length, 0)
        assert.equal(
            component.isSaveCall({
                method: 'mountAction',
                params: ['saveAsDraftWithLocation'],
            }),
            true,
        )
        assert.equal(
            component.isSaveCall({ method: 'mountAction', params: ['delete'] }),
            false,
        )
    } finally {
        if (originalWindow === undefined) delete global.window
        else global.window = originalWindow
        if (originalDocument === undefined) delete global.document
        else global.document = originalDocument
    }
})
