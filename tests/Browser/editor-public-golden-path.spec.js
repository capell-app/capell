const path = require('node:path')

const { test, expect } = require('@playwright/test')

const fixture = require('../fixtures/editor-public-golden-path.json')
const { JourneyDiagnostics } = require('./support/failure-evidence')
const {
    expectDraftIsPrivate,
    expectPublicPage,
    expectSafePublicHtml,
} = require('./support/public-output')

const baseUrl = process.env.CAPELL_GOLDEN_PATH_URL
const artifactDir =
    process.env.CAPELL_GOLDEN_PATH_ARTIFACT_DIR ??
    path.resolve('test-results/editor-public-golden-path')

test.describe('CAP-0266 editor to anonymous golden path', () => {
    let diagnostics

    test.afterEach(async ({ browser }, testInfo) => {
        void browser

        if (testInfo.status !== testInfo.expectedStatus && diagnostics) {
            await diagnostics.captureFailure(testInfo)
        }
    })

    test('installs, authors, caches, invalidates, restores, and rechecks anonymously', async ({
        browser,
    }) => {
        test.setTimeout(300_000)
        expect(baseUrl, 'CAPELL_GOLDEN_PATH_URL must be set').toBeTruthy()

        const admin = fixture.admin
        const pageFixture = fixture.page
        const publicPath = `/${pageFixture.slug}`
        const publicUrl = new URL(publicPath, baseUrl).toString()
        const forbiddenValues = [admin.name, admin.email, admin.password]

        diagnostics = new JourneyDiagnostics({
            artifactDir,
            secretValues: forbiddenValues,
        })
        const allowStylesheetRecovery = (page) =>
            diagnostics.allowResponse({
                page,
                pathname: '/resources/css/app.css',
                status: 404,
                repeat: true,
            })

        const adminContext = await browser.newContext()
        const adminPage = await adminContext.newPage()
        adminPage.setDefaultTimeout(30_000)
        adminPage.setDefaultNavigationTimeout(60_000)
        diagnostics.registerPage(adminPage, 'admin')

        await diagnostics.step('sign in', async () => {
            const response = await adminPage.goto(`${baseUrl}/admin/login`, {
                waitUntil: 'domcontentloaded',
            })

            expect(response?.status()).toBe(200)
            await adminPage.getByLabel(/email/i).first().fill(admin.email)
            await adminPage
                .locator('input[type="password"]')
                .first()
                .fill(admin.password)
            await adminPage.locator('button[type="submit"]').last().click()
            await adminPage.waitForURL(
                (url) => !url.pathname.endsWith('/admin/login'),
            )
            diagnostics.assertHealthy('sign in')
        })

        let pageId
        let editPath

        await diagnostics.step('create draft', async () => {
            diagnostics.allowLivewireRedirectOnce({
                page: adminPage,
                destinationPathname: '/admin/pages/create',
            })
            await adminPage.goto(`${baseUrl}/admin/pages/create`, {
                waitUntil: 'domcontentloaded',
            })
            await adminPage
                .locator('input[id="form.name"]')
                .first()
                .fill(pageFixture.draftTitle)
            const titleInput = adminPage
                .getByLabel('Page title', { exact: true })
                .first()
            await titleInput.fill(pageFixture.draftTitle)
            await expect(titleInput).toHaveValue(pageFixture.draftTitle)
            diagnostics.allowLivewireRedirectOnce({
                page: adminPage,
                destinationPathname: /^\/admin\/pages\/\d+\/edit$/,
            })
            await adminPage
                .getByRole('button', {
                    name: 'Save as draft',
                    exact: true,
                })
                .last()
                .click()
            await adminPage.waitForURL(/\/admin\/pages\/\d+\/edit/)

            const match = new URL(adminPage.url()).pathname.match(
                /^\/admin\/pages\/(\d+)\/edit$/,
            )
            expect(
                match,
                'draft redirect must expose the page record id',
            ).not.toBeNull()
            pageId = match[1]
            editPath = `/admin/pages/${pageId}/edit`
            forbiddenValues.push(editPath)
            diagnostics.assertHealthy('create draft')
        })

        const anonymousContext = await browser.newContext()
        const anonymousPage = await anonymousContext.newPage()
        diagnostics.registerPage(anonymousPage, 'anonymous')

        await diagnostics.step('draft stays private', async () => {
            diagnostics.allowResponseOnce({
                page: anonymousPage,
                pathname: publicPath,
                status: 404,
            })
            allowStylesheetRecovery(anonymousPage)
            const response = await anonymousPage.goto(publicUrl, {
                waitUntil: 'domcontentloaded',
            })

            await expectDraftIsPrivate({
                page: anonymousPage,
                response,
                title: pageFixture.draftTitle,
                forbiddenValues,
            })
            diagnostics.assertHealthy('draft public checkpoint')
        })

        await diagnostics.step('preview draft privately', async () => {
            const previewPagePromise = adminContext.waitForEvent('page')
            await adminPage
                .getByRole('link', { name: 'Preview draft', exact: true })
                .click()
            const previewPage = await previewPagePromise
            diagnostics.registerPage(previewPage, 'draft-preview')
            allowStylesheetRecovery(previewPage)
            await previewPage.waitForLoadState('domcontentloaded')
            await expect(
                previewPage
                    .getByRole('heading', {
                        name: pageFixture.draftTitle,
                        exact: true,
                    })
                    .first(),
            ).toBeVisible()
            await expectSafePublicHtml(previewPage, forbiddenValues, {
                cacheable: false,
            })

            const previewHeaders = await previewPage.evaluate(() =>
                fetch(window.location.href, {
                    credentials: 'same-origin',
                    redirect: 'manual',
                }).then((response) => Object.fromEntries(response.headers)),
            )
            expect(previewHeaders['cache-control']).toContain('private')
            expect(previewHeaders['cache-control']).toContain('no-store')
            expect(previewHeaders['x-robots-tag']).toBe('noindex, nofollow')
            diagnostics.assertHealthy('draft preview checkpoint')
            await previewPage.close()
        })

        await diagnostics.step('publish', async () => {
            await adminPage
                .getByRole('button', {
                    name: 'Publishing actions',
                    exact: true,
                })
                .click()
            await adminPage
                .getByRole('button', { name: 'Publish now', exact: true })
                .click()
            await expect(
                adminPage.getByText('Published.', { exact: true }).last(),
            ).toBeVisible()
            diagnostics.assertHealthy('publish')
        })

        await diagnostics.step('cached anonymous delivery', async () => {
            allowStylesheetRecovery(anonymousPage)
            const missResponse = await anonymousPage.goto(publicUrl, {
                waitUntil: 'domcontentloaded',
            })
            await expectPublicPage({
                page: anonymousPage,
                response: missResponse,
                title: pageFixture.draftTitle,
                cache: 'MISS',
                forbiddenValues,
            })
            diagnostics.assertHealthy('published MISS checkpoint')

            allowStylesheetRecovery(anonymousPage)
            const hitResponse = await anonymousPage.reload({
                waitUntil: 'domcontentloaded',
            })
            await expectPublicPage({
                page: anonymousPage,
                response: hitResponse,
                title: pageFixture.draftTitle,
                cache: 'HIT',
                forbiddenValues,
            })
            diagnostics.assertHealthy('published HIT checkpoint')
        })

        await diagnostics.step('republish changed content', async () => {
            await adminPage.bringToFront()
            await adminPage
                .getByLabel('Page title', { exact: true })
                .first()
                .fill(pageFixture.republishedTitle)
            await adminPage
                .getByRole('button', { name: 'Save', exact: true })
                .last()
                .click()
            await expect(
                adminPage.getByText('Saved', { exact: true }).last(),
            ).toBeVisible()
            diagnostics.assertHealthy('republish')
        })

        await diagnostics.step(
            'republish invalidates cached delivery',
            async () => {
                allowStylesheetRecovery(anonymousPage)
                const missResponse = await anonymousPage.goto(publicUrl, {
                    waitUntil: 'domcontentloaded',
                })
                await expectPublicPage({
                    page: anonymousPage,
                    response: missResponse,
                    title: pageFixture.republishedTitle,
                    absentTitles: [pageFixture.draftTitle],
                    cache: 'MISS',
                    forbiddenValues,
                })
                diagnostics.assertHealthy('republish MISS checkpoint')

                allowStylesheetRecovery(anonymousPage)
                const hitResponse = await anonymousPage.reload({
                    waitUntil: 'domcontentloaded',
                })
                await expectPublicPage({
                    page: anonymousPage,
                    response: hitResponse,
                    title: pageFixture.republishedTitle,
                    absentTitles: [pageFixture.draftTitle],
                    cache: 'HIT',
                    forbiddenValues,
                })
                diagnostics.assertHealthy('republish HIT checkpoint')
            },
        )

        await diagnostics.step('restore original revision', async () => {
            await adminPage.bringToFront()
            await adminPage
                .locator(
                    'button[wire\\:click*="activeRelationManager"][wire\\:click*="\'1\'"]',
                )
                .click()
            await expect(adminPage.locator('.fi-ta')).toBeVisible()
            await adminPage
                .getByRole('button', {
                    name: 'Roll back to here',
                    exact: true,
                })
                .last()
                .click()
            await expect(
                adminPage.getByRole('heading', {
                    name: /Roll back to version \d+/,
                }),
            ).toBeVisible()
            await adminPage
                .getByRole('button', {
                    name: 'Restore this version',
                    exact: true,
                })
                .click()
            await expect(
                adminPage
                    .getByText('The page was rolled back.', { exact: true })
                    .last(),
            ).toBeVisible()
            diagnostics.assertHealthy('revision restore')
        })

        await diagnostics.step(
            'restore invalidates cached delivery',
            async () => {
                allowStylesheetRecovery(anonymousPage)
                const missResponse = await anonymousPage.goto(publicUrl, {
                    waitUntil: 'domcontentloaded',
                })
                await expectPublicPage({
                    page: anonymousPage,
                    response: missResponse,
                    title: pageFixture.draftTitle,
                    absentTitles: [pageFixture.republishedTitle],
                    cache: 'MISS',
                    forbiddenValues,
                })
                diagnostics.assertHealthy('restore MISS checkpoint')
            },
        )

        await diagnostics.step('sign out', async () => {
            await adminPage.bringToFront()
            await adminPage.locator('.fi-user-menu-trigger').click()
            await adminPage
                .getByRole('menuitem', { name: 'Sign out', exact: true })
                .click()
            await adminPage.waitForURL(/\/admin\/login$/)
            diagnostics.assertHealthy('sign out')
        })

        await diagnostics.step('fresh anonymous recheck', async () => {
            const finalContext = await browser.newContext()
            const finalPage = await finalContext.newPage()
            diagnostics.registerPage(finalPage, 'final-anonymous')
            allowStylesheetRecovery(finalPage)
            const response = await finalPage.goto(publicUrl, {
                waitUntil: 'domcontentloaded',
            })

            await expectPublicPage({
                page: finalPage,
                response,
                title: pageFixture.draftTitle,
                absentTitles: [pageFixture.republishedTitle],
                cache: 'HIT',
                forbiddenValues,
            })
            diagnostics.assertHealthy('fresh anonymous checkpoint')
        })
    })
})
