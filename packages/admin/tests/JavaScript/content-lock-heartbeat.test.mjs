import assert from 'node:assert/strict'
import nodeTest from 'node:test'
import sourceHeartbeat from '../../resources/js/components/content-lock-heartbeat.js'
import publishedHeartbeat from '../../publishes/build/js/components/content-lock-heartbeat.js'

for (const [build, heartbeat] of [
    ['source', sourceHeartbeat],
    ['published', publishedHeartbeat],
]) {
    const test = (name, callback) => nodeTest(`${build}: ${name}`, callback)

    const fixture = (
        data = { name: 'Saved page', content: { title: 'Saved' } },
        storedData = null,
    ) => {
        const storage = new Map()
        const timers = new Map()
        const listeners = {}
        const ticks = []
        let commitHook
        let timerId = 0
        const key = 'page-editor:1'
        if (storedData !== null) {
            storage.set(
                key,
                JSON.stringify({
                    version: 1,
                    data: storedData,
                    savedAt: Date.now(),
                }),
            )
        }
        globalThis.window = {
            localStorage: {
                getItem: (key) => storage.get(key) ?? null,
                setItem: (key, value) => storage.set(key, value),
                removeItem: (key) => storage.delete(key),
            },
            setTimeout: (callback) => {
                timers.set(++timerId, callback)
                return timerId
            },
            clearTimeout: (id) => timers.delete(id),
            addEventListener() {},
        }
        const form = {
            addEventListener: (name, callback) => {
                listeners[name] = callback
            },
            toggleAttribute() {},
            setAttribute() {},
            querySelectorAll: () => [],
        }
        globalThis.document = { querySelector: () => form }
        const component = heartbeat({ storageKey: key })
        component.$wire = {
            data: structuredClone(data),
            $hook: (name, callback) => {
                commitHook = callback
            },
            $set: (name, value) => {
                component.$wire[name] = value
            },
        }
        component.$nextTick = (callback) => ticks.push(callback)
        const flushTicks = () => {
            while (ticks.length) ticks.shift()()
        }
        const flushTimers = () => {
            for (const [id, callback] of [...timers]) {
                timers.delete(id)
                callback()
            }
        }
        component.init()
        flushTicks()
        return {
            component,
            storage,
            key,
            flushTicks,
            flushTimers,
            event: (name, isTrusted = true) => listeners[name]?.({ isTrusted }),
            commit: (calls = []) => {
                commitHook({
                    commit: { calls },
                    succeed: (callback) => callback(),
                })
                flushTicks()
            },
        }
    }

    test('does not offer a stored draft equal to the loaded server state', () => {
        const data = { name: 'Saved page', content: { title: 'Saved' } }
        const { component, storage, key } = fixture(data, data)
        assert.equal(component.localDraftAvailable, false)
        assert.equal(storage.has(key), false)
    })

    test('compares nested object state independently of property order', () => {
        const { component } = fixture(
            {
                name: 'Saved page',
                content: { title: 'Saved', blocks: ['a', 'b'] },
            },
            {
                content: { blocks: ['a', 'b'], title: 'Saved' },
                name: 'Saved page',
            },
        )
        assert.equal(component.localDraftAvailable, false)
    })

    test('does not persist initial hydration or synthetic form events as unsaved work', () => {
        const f = fixture()
        f.component.$wire.data.content.clientDefault = []
        f.event('input', false)
        f.event('change', false)
        f.commit([{ method: 'loadWidgetFields' }])
        f.flushTimers()
        assert.equal(f.component.localDraftAvailable, false)
        assert.equal(f.storage.has(f.key), false)
    })

    test('preserves a genuine recovery draft through pristine hydration commits and release', async () => {
        const draft = { name: 'Unsaved page', content: { title: 'Edited' } }
        const f = fixture(undefined, draft)
        f.commit()
        f.flushTimers()
        await f.component.release()
        assert.equal(f.component.localDraftAvailable, true)
        assert.deepEqual(JSON.parse(f.storage.get(f.key)).data, draft)
    })

    test('keeps a recovery offer when a trusted form interaction makes no edit', () => {
        const draft = { name: 'Unsaved page' }
        const f = fixture(undefined, draft)
        f.event('click')
        f.commit()
        f.flushTimers()
        assert.equal(f.component.localDraftAvailable, true)
        assert.deepEqual(JSON.parse(f.storage.get(f.key)).data, draft)
    })

    test('waits for loaded data before offering a recovery draft', () => {
        const data = { name: 'Saved page' }
        const f = fixture(null, data)
        assert.equal(f.component.localDraftAvailable, false)
        f.component.$wire.data = data
        f.commit()
        f.flushTimers()
        assert.equal(f.component.localDraftAvailable, false)
        assert.equal(f.storage.has(f.key), false)
    })

    test('persists a genuine form edit without offering its own current work for recovery', () => {
        const f = fixture()
        f.event('input')
        f.component.$wire.data.name = 'Edited page'
        f.commit()
        f.flushTimers()
        assert.equal(JSON.parse(f.storage.get(f.key)).data.name, 'Edited page')
        assert.equal(f.component.localDraftAvailable, false)
    })

    test('captures Livewire button edits and flushes a pending draft on release', async () => {
        const f = fixture()
        f.event('click')
        f.component.$wire.data.content.blocks = ['new block']
        f.commit([{ method: 'addBlock' }])
        await f.component.release()
        assert.deepEqual(JSON.parse(f.storage.get(f.key)).data.content.blocks, [
            'new block',
        ])
    })

    test('restores genuine recovered data and keeps it recoverable on a subsequent visit', () => {
        const draft = { name: 'Unsaved page', content: { title: 'Edited' } }
        const f = fixture(undefined, draft)
        f.component.restoreLocalDraft()
        f.flushTicks()
        f.flushTimers()
        assert.deepEqual(f.component.$wire.data, draft)
        assert.equal(f.component.localDraftAvailable, false)
        assert.deepEqual(JSON.parse(f.storage.get(f.key)).data, draft)
    })

    test('clears a draft when the editor returns to its hydrated starting state', () => {
        const f = fixture()
        f.component.$wire.data.content.clientDefault = []
        const baseline = structuredClone(f.component.$wire.data)
        f.event('input')
        f.component.$wire.data.name = 'Edited page'
        f.flushTimers()
        f.component.$wire.data = baseline
        f.event('change')
        f.flushTimers()
        assert.equal(f.storage.has(f.key), false)
    })

    test('successful saves reset recovery tracking and do not make later hydration a draft', () => {
        const f = fixture()
        f.event('input')
        f.component.$wire.data.name = 'Newly saved page'
        f.component.markEditorSaved()
        f.component.$wire.data.content.clientDefault = []
        f.commit()
        f.flushTimers()
        assert.equal(f.storage.has(f.key), false)
        assert.equal(f.component.localDraftAvailable, false)
    })

    test('read-only editors cannot persist or restore drafts', () => {
        const draft = { name: 'Unsaved page' }
        const f = fixture(undefined, draft)
        f.component.setReadOnly(true)
        f.component.restoreLocalDraft()
        f.event('input')
        f.component.$wire.data.name = 'Denied edit'
        f.flushTimers()
        assert.deepEqual(JSON.parse(f.storage.get(f.key)).data, draft)
    })

    test('tracks drag and keyboard edits that dispatch synthetic change events', () => {
        for (const event of ['pointerdown', 'keydown']) {
            const f = fixture()
            f.event(event)
            f.component.$wire.data.content.blocks = ['b', 'a']
            f.event('change', false)
            f.commit()
            f.flushTimers()
            assert.deepEqual(
                JSON.parse(f.storage.get(f.key)).data.content.blocks,
                ['b', 'a'],
            )
        }
    })

    test('preserves array order when comparing recovered work with server state', () => {
        const f = fixture({ blocks: ['a', 'b'] }, { blocks: ['b', 'a'] })
        assert.equal(f.component.localDraftAvailable, true)
    })

    test('storage errors do not interrupt genuine edits', () => {
        const f = fixture()
        window.localStorage.setItem = () => {
            throw new Error('Storage quota')
        }
        f.event('input')
        f.component.$wire.data.name = 'Edited page'
        assert.doesNotThrow(f.flushTimers)
    })
}
