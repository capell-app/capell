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

test('releases the editor lock before submitting the logout form', async () => {
    const originalDocument = global.document
    const originalWindow = global.window
    const logoutUrl = 'https://capell.test/admin/logout'
    const releaseUrl =
        'https://capell.test/admin/api/pages/1/content-lock/release'
    const order = []
    let finishRelease
    let submitListener
    let resolveResubmitted
    const resubmitted = new Promise((resolve) => {
        resolveResubmitted = resolve
    })
    const logoutForm = {
        action: logoutUrl,
        addEventListener(type, listener) {
            assert.equal(type, 'submit')
            submitListener = listener
        },
        removeEventListener(type, listener) {
            assert.equal(type, 'submit')
            assert.equal(listener, submitListener)
            submitListener = undefined
        },
        requestSubmit() {
            order.push('logout')
            resolveResubmitted()
        },
    }

    global.document = { forms: [logoutForm] }
    global.window = {
        fetch(url) {
            assert.equal(url, releaseUrl)
            order.push('release-started')

            return new Promise((resolve) => {
                finishRelease = () => {
                    order.push('release-finished')
                    resolve({ ok: true })
                }
            })
        },
    }

    try {
        const { default: contentLockHeartbeat } = await import(componentUrl)
        const component = contentLockHeartbeat({ logoutUrl, releaseUrl })

        component.bindLogoutForm()

        assert.equal(typeof submitListener, 'function')

        let prevented = false

        submitListener({
            preventDefault() {
                prevented = true
            },
        })

        await Promise.resolve()

        assert.equal(prevented, true)
        assert.deepEqual(order, ['release-started'])

        finishRelease()
        await resubmitted

        assert.deepEqual(order, [
            'release-started',
            'release-finished',
            'logout',
        ])
        assert.equal(component.released, true)
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
    }
})
