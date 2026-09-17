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
