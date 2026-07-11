import { expect, test } from '@playwright/test'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const runtimePath = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    '../../resources/js/widget-runtime.js',
)

const interaction = ({
    id,
    label,
    behavior = 'modal',
    target = `/widgets/${id}`,
}) => `
    <div class="capell-interactions">
        <a
            id="${id}"
            href="https://capell.test${target}"
            data-capell-interaction
            data-capell-interaction-behavior="${behavior}"
            data-capell-interaction-close-on-backdrop="true"
            data-capell-interaction-close-label="Close"
            data-capell-interaction-loading-label="Loading content"
            data-capell-interaction-ready-label="Content loaded"
            data-capell-interaction-error-label="Content could not be loaded."
            data-capell-interaction-retry-label="Retry"
            data-capell-interaction-fallback-label="Open the full page"
            aria-label="${label}"
        >${label}</a>
        <span role="status" aria-live="polite" data-capell-interaction-status></span>
    </div>
`

async function boot(page, html, assets = {}) {
    page.on('pageerror', (error) => {
        throw error
    })
    await page.route('https://capell.test/base', (route) =>
        route.fulfill({ contentType: 'text/html', body: '<!doctype html>' }),
    )
    await page.goto('https://capell.test/base')
    await page.setContent(`
        <!doctype html>
        <html>
            <head>
                <script type="application/json" data-capell-widget-assets>${JSON.stringify(assets)}</script>
            </head>
            <body>${html}</body>
        </html>
    `)
    await page.evaluate(() => {
        window.widgetRuntimeErrors = []
        window.addEventListener('error', (event) => {
            window.widgetRuntimeErrors.push(
                event.error?.message || event.message,
            )
        })
        window.addEventListener('unhandledrejection', (event) => {
            window.widgetRuntimeErrors.push(
                event.reason?.message || String(event.reason),
            )
        })
    })
    await page.addScriptTag({ path: runtimePath })
}

test('loads a shared resource once, shares promise state, and does not activate on hover or focus', async ({
    page,
}) => {
    let assetRequests = 0
    await page.route('https://capell.test/assets/shared.js', async (route) => {
        assetRequests += 1
        await new Promise((resolve) => setTimeout(resolve, 50))
        await route.fulfill({
            contentType: 'application/javascript',
            body: 'window.sharedWidgetReady = true',
        })
    })

    await boot(
        page,
        `
            <button id="first" data-capell-widget-runtime data-capell-widget-resources='["shared"]' data-capell-widget-settings='{"loading_strategy":"interaction"}'>First</button>
            <button id="second" data-capell-widget-runtime data-capell-widget-resources='["shared"]' data-capell-widget-settings='{"loading_strategy":"interaction"}'>Second</button>
        `,
        {
            shared: [
                {
                    kind: 'js',
                    url: 'https://capell.test/assets/shared.js',
                    module: false,
                },
            ],
        },
    )

    await page.locator('#first').hover()
    await page.locator('#first').focus()
    expect(assetRequests).toBe(0)

    await Promise.all([
        page.locator('#first').click(),
        page.locator('#second').click(),
    ])
    await expect.poll(() => assetRequests).toBe(1)
    await expect
        .poll(() => page.evaluate(() => window.sharedWidgetReady))
        .toBe(true)

    expect(
        await page.evaluate(
            () =>
                window.__capellWidgetResourceStates.get(
                    'js:https://capell.test/assets/shared.js',
                )?.status,
        ),
    ).toBe('ready')
    await expect(page.locator('script[src$="/assets/shared.js"]')).toHaveCount(
        1,
    )
})

test('recognizes an eager shared URL as ready without inserting a lazy duplicate', async ({
    page,
}) => {
    let assetRequests = 0
    await page.route('https://capell.test/assets/eager.js', (route) => {
        assetRequests += 1
        return route.fulfill({
            contentType: 'application/javascript',
            body: 'window.eagerWidgetReady = true',
        })
    })

    await page.route('https://capell.test/base', (route) =>
        route.fulfill({ contentType: 'text/html', body: '<!doctype html>' }),
    )
    await page.goto('https://capell.test/base')
    await page.setContent(`
        <!doctype html>
        <html>
            <head>
                <script src="https://capell.test/assets/eager.js"></script>
                <script type="application/json" data-capell-widget-assets>
                    {"shared":[{"kind":"js","url":"https://capell.test/assets/eager.js","module":false}]}
                </script>
            </head>
            <body>
                <button id="activate" data-capell-widget-runtime data-capell-widget-resources='["shared"]' data-capell-widget-settings='{"loading_strategy":"interaction"}'>Activate</button>
            </body>
        </html>
    `)
    await page.addScriptTag({ path: runtimePath })
    await page.locator('#activate').click()

    expect(assetRequests).toBe(1)
    await expect(page.locator('script[src$="/assets/eager.js"]')).toHaveCount(1)
})

test('records failed resources and retries them only when requested', async ({
    page,
}) => {
    let requests = 0
    await page.route('https://capell.test/assets/retry.js', (route) => {
        requests += 1
        return requests === 1
            ? route.abort('failed')
            : route.fulfill({
                  contentType: 'application/javascript',
                  body: 'window.retriedWidgetReady = true',
              })
    })

    await boot(
        page,
        `<button id="resource-retry" data-capell-widget-runtime data-capell-widget-resources='["retry-resource"]' data-capell-widget-settings='{"loading_strategy":"interaction"}'>Load</button>`,
        {
            'retry-resource': [
                {
                    kind: 'js',
                    url: 'https://capell.test/assets/retry.js',
                    module: false,
                },
            ],
        },
    )

    await page.locator('#resource-retry').click()
    await expect.poll(() => requests).toBe(1)
    await expect
        .poll(() =>
            page.evaluate(
                () =>
                    window.__capellWidgetResourceStates.get(
                        'js:https://capell.test/assets/retry.js',
                    )?.status,
            ),
        )
        .toBe('failed')

    await page.evaluate(() =>
        window.CapellWidgetRuntime.retryResources(document.body, [
            'retry-resource',
        ]),
    )
    await expect.poll(() => requests).toBe(2)
    await expect
        .poll(() => page.evaluate(() => window.retriedWidgetReady))
        .toBe(true)
})

test('initialises later interaction content when its shared script is already ready', async ({
    page,
}) => {
    await page.route('https://capell.test/assets/dynamic.js', (route) =>
        route.fulfill({
            contentType: 'application/javascript',
            body: `
                const initialise = (root) => { root.dataset.ready = 'true' }
                document.querySelectorAll('[data-review-widget]').forEach(initialise)
                document.addEventListener('capell:content-ready', (event) => {
                    event.target?.querySelectorAll?.('[data-review-widget]').forEach(initialise)
                })
            `,
        }),
    )

    for (const id of ['first-dynamic', 'second-dynamic']) {
        await page.route(`https://capell.test/widgets/${id}`, (route) =>
            route.fulfill({
                contentType: 'application/json',
                body: JSON.stringify({
                    version: 2,
                    status: 'ok',
                    html: `<div data-review-widget>${id}</div>`,
                    resource_ids: ['dynamic'],
                }),
            }),
        )
    }

    await boot(
        page,
        interaction({ id: 'first-dynamic', label: 'Open first' }) +
            interaction({ id: 'second-dynamic', label: 'Open second' }),
        {
            dynamic: [
                {
                    kind: 'js',
                    url: 'https://capell.test/assets/dynamic.js',
                    module: false,
                },
            ],
        },
    )

    await page.locator('#first-dynamic').click()
    await expect(
        page
            .locator('[data-review-widget]')
            .filter({ hasText: 'first-dynamic' }),
    ).toHaveAttribute('data-ready', 'true')
    await page.keyboard.press('Escape')

    await page.locator('#second-dynamic').click()
    await expect(
        page
            .locator('[data-review-widget]')
            .filter({ hasText: 'second-dynamic' }),
    ).toHaveAttribute('data-ready', 'true')
    await expect(page.locator('script[src$="/assets/dynamic.js"]')).toHaveCount(
        1,
    )
})

test('contains focus in nested labelled dialogs and restores each trigger', async ({
    page,
}) => {
    await page.route('https://capell.test/widgets/parent', (route) =>
        route.fulfill({
            contentType: 'application/json',
            body: JSON.stringify({
                version: 2,
                status: 'ok',
                html: interaction({ id: 'nested', label: 'Open nested' }),
                resource_ids: [],
            }),
        }),
    )
    await page.route('https://capell.test/widgets/nested', (route) =>
        route.fulfill({
            contentType: 'application/json',
            body: JSON.stringify({
                version: 2,
                status: 'ok',
                html: '<p>Nested content</p><button id="last-control">Last</button>',
                resource_ids: [],
            }),
        }),
    )

    await boot(page, interaction({ id: 'parent', label: 'Open parent' }))
    await page.locator('#parent').click()

    const dialogs = page.getByRole('dialog')
    await expect(dialogs).toHaveCount(1)
    await expect(dialogs.first()).toHaveAttribute('aria-labelledby', /.+/)
    await expect(
        page.getByRole('heading', { name: 'Open parent' }),
    ).toBeVisible()

    await page.locator('#nested').click()
    await expect(page.locator('.capell-interaction-overlay')).toHaveCount(2)
    await expect(dialogs).toHaveCount(1)
    await expect(
        page.getByRole('heading', { name: 'Open nested' }),
    ).toBeVisible()

    await page.locator('#last-control').focus()
    await page.keyboard.press('Tab')
    await expect(page.getByRole('button', { name: 'Close' })).toBeFocused()

    await page.keyboard.press('Escape')
    await expect(page.locator('.capell-interaction-overlay')).toHaveCount(1)
    await expect(page.locator('#nested')).toBeFocused()

    await page.keyboard.press('Escape')
    await expect(dialogs).toHaveCount(0)
    await expect(page.locator('#parent')).toBeFocused()
})

test('keeps a fallback link and retries a failed interaction response in place', async ({
    page,
}) => {
    let attempts = 0
    await page.route('https://capell.test/widgets/retry', (route) => {
        attempts += 1
        if (attempts === 1) {
            return route.fulfill({ status: 503, body: 'Unavailable' })
        }

        return route.fulfill({
            contentType: 'application/json',
            body: JSON.stringify({
                version: 2,
                status: 'ok',
                html: '<p>Recovered content</p>',
                resource_ids: [],
            }),
        })
    })

    await boot(page, interaction({ id: 'retry', label: 'Open retry' }))
    await page.locator('#retry').hover()
    await page.locator('#retry').focus()
    expect(attempts).toBe(0)

    await page.locator('#retry').click()
    await expect.poll(() => attempts).toBe(1)
    expect(await page.evaluate(() => window.widgetRuntimeErrors)).toEqual([])
    await expect(page.locator('.capell-interaction-error-message')).toHaveText(
        'Content could not be loaded.',
    )
    await expect(
        page.getByRole('link', { name: 'Open the full page' }),
    ).toHaveAttribute('href', 'https://capell.test/widgets/retry')

    await page.getByRole('button', { name: 'Retry' }).click()
    await expect(page.getByText('Recovered content')).toBeVisible()
    expect(attempts).toBe(2)
})

test('announces and focuses inline and replace-region content', async ({
    page,
}) => {
    for (const id of ['inline', 'replace']) {
        await page.route(`https://capell.test/widgets/${id}`, (route) =>
            route.fulfill({
                contentType: 'application/json',
                body: JSON.stringify({
                    version: 2,
                    status: 'ok',
                    html: `<p>${id} content</p>`,
                    resource_ids: [],
                }),
            }),
        )
    }

    await boot(
        page,
        interaction({
            id: 'inline',
            label: 'Reveal inline',
            behavior: 'inline_reveal',
        }) +
            interaction({
                id: 'replace',
                label: 'Replace region',
                behavior: 'replace_region',
            }),
    )

    await page.locator('#inline').click()
    await expect(
        page.getByRole('region', { name: 'Reveal inline' }),
    ).toBeFocused()
    await expect(page.getByText('inline content')).toBeVisible()

    await page.locator('#replace').click()
    await expect(
        page.getByRole('region', { name: 'Replace region' }),
    ).toBeFocused()
    await expect(page.locator('#replace')).toHaveCount(0)
    await expect(page.getByText('replace content')).toBeVisible()
})
