import { readFileSync } from 'node:fs'
import { test, expect } from '@playwright/test'

// Run against an isolated installed consumer with a super-admin and an existing
// page on Site A. A supports English/French/German; B supports English only; C
// supports English/German. Supply JSON via CAPELL_PAGE_SITE_FIXTURE containing
// baseUrl, email, password, pageId, siteAId, siteBId, siteCId, englishId, frenchId
// and germanId. This suite never saves either form.
for (const quickCreate of [false, true]) {
    for (const retainActive of [false, true]) {
        test(`${retainActive ? 'viewed German' : 'fallback English'} editor stays visible after language removal in ${quickCreate ? 'quick create' : 'ordinary creation'}`, async ({
            page,
        }) => {
            const fixturePath = process.env.CAPELL_PAGE_SITE_FIXTURE
            expect(
                fixturePath,
                'CAPELL_PAGE_SITE_FIXTURE must be set',
            ).toBeTruthy()
            const fixture = JSON.parse(readFileSync(fixturePath, 'utf8'))
            const errors = []
            page.on('pageerror', (error) => errors.push(error.message))

            await page.goto(`${fixture.baseUrl}/admin/login`)
            await page.getByLabel(/email/i).first().fill(fixture.email)
            await page.locator('input[type="password"]').fill(fixture.password)
            await page.locator('button[type="submit"]').last().click()
            await page.waitForURL(
                (url) => !url.pathname.endsWith('/admin/login'),
            )
            await page.goto(
                `${fixture.baseUrl}/admin/pages/${quickCreate ? `${fixture.pageId}/edit` : 'create'}`,
            )
            const componentId = await page
                .locator(
                    `form[wire\\:submit="${quickCreate ? 'save' : 'create'}"]`,
                )
                .evaluate((form) =>
                    form.closest('[wire\\:id]').getAttribute('wire:id'),
                )
            const statePath = quickCreate ? 'mountedActions.0.data' : 'data'
            await page.evaluate(
                async ({ componentId, quickCreate, statePath, fixture }) => {
                    const wire = window.Livewire.find(componentId)
                    if (quickCreate) await wire.mountAction('create')
                    await wire.set(`${statePath}.site_id`, fixture.siteAId)
                    await wire.set(`${statePath}.translations`, {
                        english: {
                            language_id: fixture.englishId,
                            title: 'Retained English title',
                            content: '<p>Retained English draft</p>',
                        },
                        french: {
                            language_id: fixture.frenchId,
                            title: 'Titre français',
                        },
                        german: {
                            language_id: fixture.germanId,
                            title: 'Retained German title',
                            content: '<p>Retained German draft</p>',
                        },
                    })
                },
                { componentId, quickCreate, statePath, fixture },
            )

            const englishTitle = page.locator(
                'input[id$=".translations.english.title"]',
            )
            const frenchTitle = page.locator(
                'input[id$=".translations.french.title"]',
            )
            const germanTitle = page.locator(
                'input[id$=".translations.german.title"]',
            )
            const retainedTitle = retainActive ? germanTitle : englishTitle
            const repeater = englishTitle.locator(
                'xpath=ancestor::*[@*[name()="x-on:refresh-tabs.window"]][1]',
            )
            const tabs = repeater.getByRole('tab')
            const viewedTab = repeater.locator(
                `[role="tab"][data-tab-index="${retainActive ? 3 : 2}"]`,
            )
            await expect(englishTitle).toBeVisible()
            await expect(germanTitle).toBeHidden()
            await viewedTab.click()
            await expect(viewedTab).toHaveAttribute('aria-selected', 'true')
            await expect(retainActive ? germanTitle : frenchTitle).toBeVisible()
            await expect(englishTitle).toBeHidden()

            await page.evaluate(
                async ({ componentId, statePath, siteBId }) => {
                    await window.Livewire.find(componentId).set(
                        `${statePath}.site_id`,
                        siteBId,
                    )
                },
                {
                    componentId,
                    statePath,
                    siteBId: retainActive ? fixture.siteCId : fixture.siteBId,
                },
            )
            await page
                .getByRole('button', {
                    name: 'Use site languages',
                    exact: true,
                })
                .click()
            const confirmation = page.getByRole('dialog').last()
            await expect(confirmation).toBeVisible()
            await confirmation
                .getByRole('button', { name: 'Confirm', exact: true })
                .click()

            await expect(frenchTitle).toHaveCount(0)
            await expect(tabs).toHaveCount(retainActive ? 2 : 1)
            await expect(retainedTitle).toBeVisible()
            await expect(retainedTitle).toHaveValue(
                retainActive
                    ? 'Retained German title'
                    : 'Retained English title',
            )
            if (retainActive) {
                await expect(tabs.nth(1)).toHaveAttribute(
                    'aria-selected',
                    'true',
                )
                await expect(englishTitle).toBeHidden()
            } else {
                await expect(tabs.first()).toHaveAttribute(
                    'aria-selected',
                    'true',
                )
                await expect(germanTitle).toHaveCount(0)
            }
            await retainedTitle.fill('Edited retained title')
            await retainedTitle.blur()
            await expect(retainedTitle).toHaveValue('Edited retained title')
            await expect
                .poll(() =>
                    page.evaluate(
                        ({ componentId, statePath, language }) =>
                            window.Livewire.find(componentId).get(
                                `${statePath}.translations.${language}.title`,
                            ),
                        {
                            componentId,
                            statePath,
                            language: retainActive ? 'german' : 'english',
                        },
                    ),
                )
                .toBe('Edited retained title')
            const state = await page.evaluate(
                ({ componentId, statePath }) =>
                    window.Livewire.find(componentId).get(statePath),
                { componentId, statePath },
            )
            expect(state.activeTab).toBe(retainActive ? 2 : 1)
            expect(state.translations.english.content).toBe(
                '<p>Retained English draft</p>',
            )
            if (retainActive) {
                expect(state.translations.german.content).toBe(
                    '<p>Retained German draft</p>',
                )
                expect(state.translations.german.title).toBe(
                    'Edited retained title',
                )
            }
            expect(errors).toEqual([])
        })
    }
}
