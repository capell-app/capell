import { expect, test } from '@playwright/test'
import { readFile } from 'node:fs/promises'

const source = await readFile(
    new URL(
        '../../resources/js/components/content-lock-heartbeat.js',
        import.meta.url,
    ),
    'utf8',
)

// localhost is a secure context, which the component needs for Web Locks and
// crypto.randomUUID(). Every request to it is answered by page.route below.
const origin = 'http://localhost:4173'
const pageKey = 'capell:page-editor:7:42:en'
const keys = {
    mine: `${pageKey}:session-a`,
    mineEmergency: `${pageKey}:emergency:11111111-1111-4111-8111-111111111111`,
    myOtherPage: 'capell:page-editor:7:43:en:session-a',
    otherUser: 'capell:page-editor:8:42:en:session-a',
    unrelated: 'unrelated-preference',
}

// Mirrors packages/admin/resources/views/components/content-lock-heartbeat.blade.php:
// the component is created with the view's config object and init() is called
// by x-init, which Alpine runs with $nextTick and $wire in scope. The logout
// form is the one whose action equals config.logoutUrl, found in document.forms.
const editorHtml = `<!doctype html>
<html><body>
    <form id="form"><input name="title" aria-label="Title" value="Original"></form>
    <form id="logout" method="post" action="${origin}/admin/logout">
        <input type="hidden" name="_token" value="token">
        <button type="submit">Sign out</button>
    </form>
    <div data-capell-content-lock-heartbeat></div>
</body></html>`

let heartbeat

function draft(label) {
    return JSON.stringify({
        version: 1,
        data: { title: label },
        savedAt: Date.now(),
        revision: label,
    })
}

async function open(page, heartbeatAnswer) {
    heartbeat = heartbeatAnswer
    await page.route(`${origin}/**`, async (route) => {
        const url = new URL(route.request().url())

        if (url.pathname === '/heartbeat') {
            if (heartbeat === 'abort') return route.abort('failed')
            return route.fulfill({
                status: heartbeat,
                contentType: 'application/json',
                body: '{}',
            })
        }

        if (url.pathname === '/release') {
            return route.fulfill({ status: 204 })
        }

        if (url.pathname === '/component.js') {
            return route.fulfill({
                status: 200,
                contentType: 'text/javascript',
                body: source,
            })
        }

        if (url.pathname === '/admin/logout') {
            return route.fulfill({
                status: 200,
                contentType: 'text/html',
                body: '<!doctype html><title>Signed out</title><p>Signed out</p>',
            })
        }

        return route.fulfill({
            status: 200,
            contentType: 'text/html',
            body: editorHtml,
        })
    })
    await page.goto(`${origin}/admin/pages/42/edit`)
    await page.evaluate(
        ({ keys, drafts }) => {
            localStorage.clear()
            for (const [name, key] of Object.entries(keys)) {
                localStorage.setItem(key, drafts[name])
            }
        },
        {
            keys,
            drafts: Object.fromEntries(
                Object.keys(keys).map((name) => [name, draft(name)]),
            ),
        },
    )
}

async function mount(page) {
    await page.evaluate(
        async ({ origin, pageKey }) => {
            const { default: heartbeatComponent } = await import(
                `${origin}/component.js`
            )
            const component = heartbeatComponent({
                heartbeatUrl: `${origin}/heartbeat`,
                releaseUrl: `${origin}/release`,
                logoutUrl: `${origin}/admin/logout`,
                csrfToken: 'token',
                pageId: 42,
                storageKey: pageKey,
                localDraftSession: 'session-a',
            })
            component.$nextTick = (callback) => queueMicrotask(callback)
            component.$wire = { data: { title: 'Original' } }
            window.component = component
            component.init()
        },
        { origin, pageKey },
    )
}

const stored = (page) => page.evaluate(() => Object.keys(localStorage).sort())

const names = (...list) => list.map((name) => keys[name]).sort()

test('logging out removes the signed-in user drafts before the page unloads and writes no emergency copy', async ({
    page,
}) => {
    await open(page, 200)
    await mount(page)
    await expect
        .poll(() => page.evaluate(() => window.component.heartbeatUnavailable))
        .toBe(false)
    // An unsaved edit that would normally be copied to storage while unloading.
    await page.evaluate(() => {
        window.component.$wire.data = { title: 'Edited, not yet saved' }
    })

    // The page cannot be evaluated while the sign-out navigation is pending,
    // so the document records what storage held as it unloaded (after the
    // component's own unload listeners) and the next document reads it back.
    await page.evaluate(() => {
        window.addEventListener('pagehide', () => {
            sessionStorage.setItem(
                'storageAtUnload',
                JSON.stringify(Object.keys(localStorage).sort()),
            )
        })
    })

    await page.getByRole('button', { name: 'Sign out' }).click()
    await expect(page.getByText('Signed out')).toBeVisible()

    const storedAtUnload = await page.evaluate(() =>
        JSON.parse(sessionStorage.getItem('storageAtUnload')),
    )
    expect(storedAtUnload).toEqual(names('otherUser', 'unrelated'))
    expect(await stored(page)).toEqual(names('otherUser', 'unrelated'))
})

for (const status of [401, 419]) {
    test(`a ${status} heartbeat answer removes every draft of the signed-in user`, async ({
        page,
    }) => {
        await open(page, status)
        await mount(page)

        await expect
            .poll(() => stored(page))
            .toEqual(names('otherUser', 'unrelated'))
    })
}

test('a 403 heartbeat answer removes only the drafts of that page', async ({
    page,
}) => {
    await open(page, 403)
    await mount(page)

    await expect
        .poll(() => stored(page))
        .toEqual(names('myOtherPage', 'otherUser', 'unrelated'))
})

for (const answer of [500, 'abort']) {
    test(`a ${answer === 'abort' ? 'failed request' : `${answer} answer`} keeps every draft`, async ({
        page,
    }) => {
        await open(page, answer)
        await mount(page)
        await expect
            .poll(() =>
                page.evaluate(() => window.component.heartbeatUnavailable),
            )
            .toBe(true)

        expect(await stored(page)).toEqual(Object.values(keys).sort())
    })
}
