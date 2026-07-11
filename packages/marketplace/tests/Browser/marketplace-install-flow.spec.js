import { expect, test } from '@playwright/test'

const cmsUrl = (
    process.env.CAPELL_MARKETPLACE_SMOKE_CMS_URL || 'http://capell-ruby.test'
).replace(/\/$/, '')
const cmsBackendUrl =
    process.env.CAPELL_MARKETPLACE_SMOKE_CMS_BACKEND_URL?.replace(/\/$/, '')
const appUrl = (
    process.env.CAPELL_MARKETPLACE_SMOKE_APP_URL || 'http://capell-app.test'
).replace(/\/$/, '')
const cmsAdminEmail =
    process.env.CAPELL_MARKETPLACE_SMOKE_ADMIN_EMAIL ||
    'codex-marketplace-buyer@example.com'
const cmsAdminPassword =
    process.env.CAPELL_MARKETPLACE_SMOKE_ADMIN_PASSWORD || 'password'
const appAccountEmail =
    process.env.CAPELL_MARKETPLACE_SMOKE_APP_EMAIL ||
    'codex-marketplace-buyer@example.com'
const appAccountPassword =
    process.env.CAPELL_MARKETPLACE_SMOKE_APP_PASSWORD || cmsAdminPassword
const allowLocalQueue =
    process.env.CAPELL_MARKETPLACE_SMOKE_ALLOW_LOCAL_QUEUE === 'true'
const extensionName =
    process.env.CAPELL_MARKETPLACE_SMOKE_EXTENSION || 'Marketplace Smoke QA'
const installedExtensionName =
    process.env.CAPELL_MARKETPLACE_SMOKE_INSTALLED_EXTENSION ||
    'Marketplace Smoke QA'

async function routeCmsCanonicalHostToBackend(page) {
    if (!cmsBackendUrl) {
        return
    }

    const canonicalHost = new URL(cmsUrl).hostname
    const backend = new URL(cmsBackendUrl)

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
                host: new URL(cmsUrl).host,
            },
        })

        await route.fulfill({ response })
    })
}

async function skipWhenUnreachable(page, targetUrl, label) {
    const response = await page.request
        .get(targetUrl, {
            failOnStatusCode: false,
            timeout: 15000,
        })
        .catch(() => null)

    if (response && response.status() < 500) {
        return
    }

    throw new Error(`${label} is unreachable: ${targetUrl}`)
}

function requireMarketplacePrecondition(condition, message) {
    if (condition) {
        return
    }

    throw new Error(message)
}

async function login(page, loginUrl, email, password) {
    const response = await page.goto(loginUrl, {
        waitUntil: 'domcontentloaded',
    })
    requireMarketplacePrecondition(
        response?.status() === 200,
        `${loginUrl} did not return a 200 response`,
    )

    const emailField = page.getByLabel(/email/i).first()
    requireMarketplacePrecondition(
        await emailField
            .waitFor({ state: 'visible', timeout: 15000 })
            .then(() => true)
            .catch(() => false),
        `${loginUrl} is missing the expected email login field`,
    )

    await page.getByLabel(/email/i).first().fill(email)
    await page.locator('input[type="password"]').first().fill(password)
    await page.locator('button[type="submit"], form button').last().click()
    await page
        .waitForURL((url) => !url.pathname.endsWith('/login'), {
            timeout: 15000,
        })
        .catch(() => null)

    requireMarketplacePrecondition(
        !new URL(page.url()).pathname.endsWith('/login'),
        `${loginUrl} did not authenticate with the configured smoke credentials`,
    )
}

function marketplaceDialog(page) {
    return page
        .locator('.fi-modal')
        .filter({ hasText: 'Extensions Marketplace' })
}

async function openMarketplace(page) {
    const response = await page.goto(`${cmsUrl}/admin/extensions?page=2`, {
        waitUntil: 'domcontentloaded',
    })

    requireMarketplacePrecondition(
        response?.status() === 200,
        `${cmsUrl}/admin/extensions did not return a 200 response`,
    )
    requireMarketplacePrecondition(
        /\/admin\/extensions/.test(page.url()),
        `${cmsUrl}/admin/extensions is not available to the smoke account`,
    )

    const marketplaceButton = page.locator(
        'button.fi-ac-btn-action:has-text("Extensions Marketplace")',
    )

    requireMarketplacePrecondition(
        await marketplaceButton
            .waitFor({ state: 'visible', timeout: 15000 })
            .then(() => true)
            .catch(() => false),
        `${cmsUrl}/admin/extensions is missing the Extensions Marketplace action`,
    )
    await marketplaceButton.click()

    const marketplaceHeader = page.locator('.fi-modal-header').filter({
        hasText: 'Extensions Marketplace',
    })

    requireMarketplacePrecondition(
        await marketplaceHeader
            .waitFor({ state: 'visible', timeout: 15000 })
            .then(() => true)
            .catch(() => false),
        `${cmsUrl}/admin/extensions did not open the Extensions Marketplace dialog`,
    )
}

async function selectMarketplaceExtension(page) {
    const dialog = marketplaceDialog(page)

    const search = dialog
        .getByPlaceholder('Search extensions, capabilities, publishers...')
        .or(dialog.getByLabel(/search/i))
        .first()

    await expect(search).toBeVisible({ timeout: 30000 })

    if (await search.isVisible().catch(() => false)) {
        await search.fill(extensionName)
    }

    const extensionCard = dialog
        .locator('article, tr, [role="row"], [data-extension-card]')
        .filter({ hasText: extensionName })
        .first()

    if (
        !(await extensionCard.isVisible({ timeout: 15000 }).catch(() => false))
    ) {
        throw new Error(`${extensionName} is not available to download.`)
    }

    await expect(extensionCard).toBeVisible({ timeout: 15000 })
    await extensionCard
        .getByRole('button', {
            name: /select|download|install|continue|review/i,
        })
        .or(
            extensionCard.getByRole('link', {
                name: /select|download|install|continue|review/i,
            }),
        )
        .first()
        .click()

    const review = dialog
        .getByRole('button', { name: /download selected|install/i })
        .last()

    await expect(review).toBeVisible({ timeout: 15000 })
    await review.click()

    const finalInstall = dialog
        .getByRole('button', {
            name: /install \d+ package|download|continue|start|confirm/i,
        })
        .last()

    await expect(finalInstall).toBeVisible({ timeout: 15000 })
    await finalInstall.click()
}

async function continueCapellaFlow(page) {
    const appHost = new URL(appUrl).hostname
    const cmsHost = new URL(cmsUrl).hostname

    await page
        .waitForURL((url) => url.hostname === appHost, { timeout: 30000 })
        .catch(async () => {
            const currentUrl = new URL(page.url())

            if (currentUrl.hostname !== cmsHost) {
                throw new Error(
                    `Expected Marketplace approval redirect to ${appHost}; current URL is ${page.url()}`,
                )
            }

            if (allowLocalQueue) {
                const localOperationQueued = page
                    .getByText(
                        /package operation|operation queued|install queued|installation queued/i,
                    )
                    .first()

                if (
                    !(await localOperationQueued.isVisible().catch(() => false))
                ) {
                    throw new Error(
                        `Marketplace install action did not navigate to Capella App approval or queue a local operation. Current URL is ${page.url()}`,
                    )
                }

                return
            }

            throw new Error(
                `Marketplace install action did not navigate to Capella App approval. Current URL is ${page.url()}`,
            )
        })

    if (new URL(page.url()).hostname === cmsHost) {
        return
    }

    await expect(
        page.getByText(/marketplace install flow|extension download/i).first(),
    ).toBeVisible()

    const loginLink = page.getByRole('link', { name: /log in to continue/i })

    if (page.url().includes('/login')) {
        await login(page, page.url(), appAccountEmail, appAccountPassword)
    } else if (await loginLink.isVisible().catch(() => false)) {
        await login(
            page,
            await loginLink.evaluate((element) => element.href),
            appAccountEmail,
            appAccountPassword,
        )

        await expect(
            page
                .getByText(/marketplace install flow|extension download/i)
                .first(),
        ).toBeVisible({ timeout: 30000 })
    }

    const testPayment = page.getByRole('button', {
        name: /continue with test payment/i,
    })
    const payment = page.getByRole('button', { name: /continue to payment/i })
    const approve = page.getByRole('button', {
        name: /return to/i,
    })

    if (await testPayment.isVisible().catch(() => false)) {
        await testPayment.click()
        await page.waitForURL(/purchase=thanks/, { timeout: 30000 })
    } else if (await payment.isVisible().catch(() => false)) {
        await payment.click()
        await page.waitForURL(/purchase=thanks/, { timeout: 30000 })
    }

    await approve.click()
    await page.waitForURL((url) => url.hostname === new URL(cmsUrl).hostname, {
        timeout: 30000,
    })
}

async function uninstallAndDeleteExtension(page, { required = false } = {}) {
    await page.goto(`${cmsUrl}/admin/extensions?page=2`, {
        waitUntil: 'domcontentloaded',
    })

    const installedExtension = page
        .locator('article, tr, [role="row"], [data-extension-card]')
        .filter({ hasText: installedExtensionName })
        .first()

    if (
        !(await installedExtension
            .isVisible({ timeout: 15000 })
            .catch(() => false))
    ) {
        if (required) {
            throw new Error(
                `${installedExtensionName} was not visible on the Extensions page for uninstall/delete.`,
            )
        }

        return
    }

    const manage = installedExtension
        .getByRole('button', { name: /manage|actions|open/i })
        .or(
            installedExtension.getByRole('link', {
                name: /manage|actions|open/i,
            }),
        )
        .first()

    if (await manage.isVisible().catch(() => false)) {
        await manage.click()
    }

    const uninstall = page.getByRole('button', { name: /uninstall/i }).first()
    if (!(await uninstall.isVisible().catch(() => false))) {
        if (required) {
            throw new Error(
                `${installedExtensionName} did not expose an uninstall action.`,
            )
        }
    } else {
        await uninstall.click()

        const confirm = page
            .getByRole('button', { name: /uninstall|confirm/i })
            .last()
        if (!(await confirm.isVisible().catch(() => false))) {
            if (required) {
                throw new Error(
                    `${installedExtensionName} did not expose an uninstall confirmation.`,
                )
            }
        } else {
            await confirm.click()
        }
    }

    const deleteData = page
        .getByRole('button', { name: /delete data|delete extension data/i })
        .first()
    if (!(await deleteData.isVisible().catch(() => false))) {
        if (required) {
            throw new Error(
                `${installedExtensionName} did not expose a delete data action.`,
            )
        }
    } else {
        await deleteData.click()

        const confirmDelete = page
            .getByRole('button', { name: /delete|confirm/i })
            .last()
        if (!(await confirmDelete.isVisible().catch(() => false))) {
            if (required) {
                throw new Error(
                    `${installedExtensionName} did not expose a delete confirmation.`,
                )
            }
        } else {
            await confirmDelete.click()
        }
    }

    if (required) {
        await expect(installedExtension).not.toBeVisible({ timeout: 30000 })
    }
}

test.describe('marketplace hosted install flow smoke', () => {
    test.setTimeout(120000)

    test('hosted install can approve return uninstall and delete locally', async ({
        page,
    }) => {
        await routeCmsCanonicalHostToBackend(page)
        await skipWhenUnreachable(page, cmsBackendUrl || cmsUrl, 'CMS')
        await skipWhenUnreachable(page, appUrl, 'Capella App')

        const consoleErrors = []
        const pageErrors = []

        page.on('console', (message) => {
            if (message.type() === 'error') {
                consoleErrors.push(message.text())
            }
        })
        page.on('pageerror', (error) => {
            pageErrors.push(error.message)
        })

        await login(
            page,
            `${cmsUrl}/admin/login`,
            cmsAdminEmail,
            cmsAdminPassword,
        )
        await openMarketplace(page)
        await uninstallAndDeleteExtension(page)
        await openMarketplace(page)
        await selectMarketplaceExtension(page)
        await continueCapellaFlow(page)

        await expect(page).toHaveURL(/\/admin\/extensions|\/admin\/marketplace/)
        await expect(
            page.getByRole('heading', { name: 'Extensions' }),
        ).toBeVisible({
            timeout: 30000,
        })

        await uninstallAndDeleteExtension(page, { required: true })

        expect(consoleErrors).toEqual([])
        expect(pageErrors).toEqual([])
    })
})
