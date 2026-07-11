import { expect, test } from '@playwright/test'

const baseUrl = (
    process.env.CAPELL_SMOKE_URL || 'http://capell-ruby.test'
).replace(/\/$/, '')
const backendUrl = process.env.CAPELL_SMOKE_BACKEND_URL?.replace(/\/$/, '')
const adminBaseUrl = backendUrl || baseUrl
const adminEmail = process.env.CAPELL_SMOKE_ADMIN_EMAIL || 'demo@example.com'
const adminPassword = process.env.CAPELL_SMOKE_ADMIN_PASSWORD || 'password'
const publicSmokeViewports = [
    { name: 'desktop', viewport: { width: 1280, height: 900 } },
    { name: 'mobile', viewport: { width: 375, height: 812 } },
]

async function routeCanonicalHostToBackend(page) {
    if (!backendUrl) {
        return
    }

    const canonicalHost = new URL(baseUrl).hostname
    const backend = new URL(backendUrl)

    await page.route('**/*', async (route) => {
        const request = route.request()
        const requestUrl = new URL(request.url())

        if (requestUrl.hostname !== canonicalHost) {
            await route.continue()

            return
        }

        requestUrl.protocol = backend.protocol
        requestUrl.hostname = backend.hostname
        requestUrl.port = backend.port

        const response = await route.fetch({
            url: requestUrl.toString(),
            headers: {
                ...request.headers(),
                host: new URL(baseUrl).host,
            },
        })

        await route.fulfill({ response })
    })
}

async function skipWhenUnreachable(page) {
    const targetUrl = backendUrl || baseUrl
    const response = await page.request
        .get(targetUrl, {
            failOnStatusCode: false,
            headers: backendUrl ? { Host: new URL(baseUrl).host } : undefined,
            timeout: 15000,
        })
        .catch(() => null)

    if (response && response.status() < 500) {
        return
    }

    throw new Error(`CAPELL_SMOKE_URL is unreachable: ${baseUrl}`)
}

function requireSmokePrecondition(condition, message) {
    if (condition) {
        return
    }

    throw new Error(message)
}

async function expectAnonymousPublicHtmlIsSafe(page) {
    const html = await page.content()
    const forbiddenLiterals = [
        'window.beaconData',
        'data-capell-authoring',
        'data-capell-editor',
        'data-capell-editor-url',
        'capell-editor',
        'capell-authoring',
        'field_path',
        'field-path',
        'model_id',
        'model-id',
        'signedEditorUrl',
        'editor_url',
    ]
    const forbiddenPatterns = [
        /\/admin\/[^"' <>\n]+\/edit/i,
        /\/admin\/[^"' <>\n]+\?(?=[^"' <>\n]*signature=)/i,
    ]

    for (const forbiddenLiteral of forbiddenLiterals) {
        expect(
            html,
            `public HTML must not contain ${forbiddenLiteral}`,
        ).not.toContain(forbiddenLiteral)
    }

    for (const forbiddenPattern of forbiddenPatterns) {
        expect(
            html,
            `public HTML must not match ${forbiddenPattern}`,
        ).not.toMatch(forbiddenPattern)
    }
}

test.describe('default demo smoke', () => {
    for (const publicSmokeViewport of publicSmokeViewports) {
        test(`public homepage renders the default frontend safely on ${publicSmokeViewport.name}`, async ({
            page,
        }) => {
            await page.setViewportSize(publicSmokeViewport.viewport)
            await routeCanonicalHostToBackend(page)
            await skipWhenUnreachable(page)

            const consoleErrors = []
            page.on('console', (message) => {
                if (message.type() === 'error') {
                    consoleErrors.push(message.text())
                }
            })

            const response = await page.goto(baseUrl, {
                waitUntil: 'domcontentloaded',
            })
            requireSmokePrecondition(
                response?.status() === 200,
                `CAPELL_SMOKE_URL did not return a 200 response: ${baseUrl}`,
            )

            await page.waitForLoadState('load')

            requireSmokePrecondition(
                /Bulldog|Capell|Ruby/i.test(await page.title()),
                `CAPELL_SMOKE_URL does not appear to be the default demo homepage: ${baseUrl}`,
            )

            const stylesheets = await page.$$eval(
                'link[rel="stylesheet"]',
                (links) => links.map((link) => link.href),
            )
            const images = await page.$$eval('img', (images) =>
                images.map((image) => ({
                    src: image.currentSrc || image.src,
                    complete: image.complete,
                    width: image.naturalWidth,
                })),
            )

            requireSmokePrecondition(
                stylesheets.some(
                    (href) =>
                        href.includes('/build/') || href.includes('capell'),
                ),
                `CAPELL_SMOKE_URL is missing expected public stylesheets: ${baseUrl}`,
            )
            expect(
                images
                    .filter((image) => image.src)
                    .every((image) => image.complete && image.width > 0),
            ).toBe(true)

            const swiperCount = await page.evaluate(() => {
                return document.querySelectorAll(
                    '.swiper-initialized, [data-swiper-initialized="true"]',
                ).length
            })
            const swiperTotal = await page.locator('.swiper').count()
            if (swiperTotal > 0) {
                expect(swiperCount).toBeGreaterThanOrEqual(1)
            }

            await expectAnonymousPublicHtmlIsSafe(page)
            expect(consoleErrors).toEqual([])
        })
    }

    test('admin login and homepage edit surface expose key editable fields', async ({
        page,
    }) => {
        await skipWhenUnreachable(page)

        const loginResponse = await page.goto(`${adminBaseUrl}/admin/login`, {
            waitUntil: 'domcontentloaded',
        })
        requireSmokePrecondition(
            loginResponse?.status() === 200,
            `CAPELL_SMOKE_URL admin login did not return a 200 response: ${adminBaseUrl}/admin/login`,
        )

        const livewireScriptCount = await page
            .locator('script[src*="livewire"], script[data-navigate-once]')
            .count()
        requireSmokePrecondition(
            livewireScriptCount > 0,
            `CAPELL_SMOKE_URL admin login is missing expected Livewire assets: ${adminBaseUrl}/admin/login`,
        )

        await page.getByLabel(/email/i).first().fill(adminEmail)
        await page.locator('input[type="password"]').first().fill(adminPassword)
        await page.locator('button[type="submit"], form button').last().click()

        await page
            .waitForURL((url) => !url.pathname.endsWith('/admin/login'), {
                timeout: 10000,
            })
            .catch(() => null)

        requireSmokePrecondition(
            !new URL(page.url()).pathname.endsWith('/admin/login'),
            `CAPELL_SMOKE_URL admin smoke credentials did not authenticate: ${adminBaseUrl}/admin/login`,
        )

        let pagesResponse = null

        try {
            pagesResponse = await page.goto(
                `${adminBaseUrl}/admin/pages/1/edit`,
                {
                    waitUntil: 'domcontentloaded',
                },
            )
        } catch (error) {
            if (!String(error).includes('net::ERR_ABORTED')) {
                throw error
            }
        }

        if (pagesResponse) {
            requireSmokePrecondition(
                pagesResponse.status() < 500,
                `CAPELL_SMOKE_URL admin edit page returned ${pagesResponse.status()}: ${adminBaseUrl}/admin/pages/1/edit`,
            )
        }

        requireSmokePrecondition(
            /\/admin\/pages\/1\/edit/.test(page.url()),
            `CAPELL_SMOKE_URL admin edit page is not available: ${adminBaseUrl}/admin/pages/1/edit`,
        )

        await expect(page.getByLabel(/title/i).first()).toBeVisible()
        await expect(page.getByText(/image/i).first()).toBeVisible()
        await expect(page.getByText(/content/i).first()).toBeVisible()

        await page
            .getByText(/seo settings/i)
            .first()
            .click()
        await expect(page.getByText(/meta title/i).first()).toBeVisible()

        const layoutFieldCount = await page.getByText(/layout/i).count()
        expect(layoutFieldCount).toBeGreaterThan(0)
    })
})
