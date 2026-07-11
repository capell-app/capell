#!/usr/bin/env node
// Captures the admin-UI screenshots referenced in
// packages/admin/docs/page-creation-and-approval-flow.md
//
// Usage:
//   ADMIN_URL=http://capell-ruby.local/admin \
//   ADMIN_EMAIL=you@example.com \
//   ADMIN_PASSWORD=secret \
//   node scripts/capture-admin-screenshots.mjs
//
// Optional:
//   SCREENSHOT_OUTPUT_DIR=packages/admin/docs/images/screenshots
//   SCREENSHOT_FULL_PAGE=true
//
// Requires playwright. Install once with:
//   npm install --save-dev playwright
//   npx playwright install chromium

import { mkdir } from 'node:fs/promises'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const {
    ADMIN_URL = 'http://capell-ruby.local/admin',
    FRONTEND_URL = 'http://capell-ruby.local',
    ADMIN_EMAIL,
    ADMIN_PASSWORD,
    SCREENSHOT_FULL_PAGE = 'true',
    SCREENSHOT_OUTPUT_DIR,
} = process.env

const outDir = SCREENSHOT_OUTPUT_DIR
    ? resolve(process.cwd(), SCREENSHOT_OUTPUT_DIR)
    : resolve(__dirname, '../packages/admin/docs/images/screenshots')

const fullPageScreenshots = SCREENSHOT_FULL_PAGE !== 'false'

if (!ADMIN_EMAIL || !ADMIN_PASSWORD) {
    throw new Error('Set ADMIN_EMAIL and ADMIN_PASSWORD env vars.')
}

await mkdir(outDir, { recursive: true })

const playwright = await import(process.env.PLAYWRIGHT_IMPORT ?? 'playwright')
const { chromium } = playwright.chromium ? playwright : playwright.default

const browser = await chromium.launch()
const context = await browser.newContext({
    viewport: { width: 1440, height: 900 },
})
const page = await context.newPage()

async function assertNoScreenshotPollution() {
    const pollution = await page
        .evaluate(() => {
            const debugbarSelector = [
                '#phpdebugbar',
                '.phpdebugbar',
                '[id^="phpdebugbar"]',
                '[class*="phpdebugbar"]',
                '[data-debugbar]',
            ].join(',')
            const debugbar = document.querySelector(debugbarSelector)

            if (debugbar) {
                return 'Laravel Debugbar markup is present in the screenshot page.'
            }

            const bodyText = document.body?.innerText ?? ''

            for (const marker of [
                'file_put_contents(',
                'testbench-core/laravel/server.php',
                'Broken pipe',
                'sf-dump-server-error',
                'Page URL from capell-app/site-discovery.',
            ]) {
                if (bodyText.includes(marker)) {
                    return `PHP/runtime notice leaked into the screenshot page: ${marker}`
                }
            }

            return null
        })
        .catch(() => null)

    if (pollution) {
        throw new Error(pollution)
    }
}

async function preparePageForScreenshot() {
    await page.waitForLoadState('networkidle').catch(() => {})
    await page.evaluate(() => document.fonts?.ready).catch(() => {})
    await page.waitForTimeout(500)
    await assertNoScreenshotPollution()

    await page
        .addStyleTag({
            content: [
                '*, ::before, ::after { animation-duration: 0s !important; transition-duration: 0s !important; transition-delay: 0s !important; }',
                '#phpdebugbar,',
                '.phpdebugbar,',
                '[id^="phpdebugbar"],',
                '[class*="phpdebugbar"],',
                '[data-debugbar],',
                '.sf-dump-server-error { display: none !important; visibility: hidden !important; }',
            ].join('\n'),
        })
        .catch(() => {})
}

async function shot(name, waitForSelector) {
    if (waitForSelector) {
        await page.waitForSelector(waitForSelector, { state: 'attached' })
    }
    await preparePageForScreenshot()
    await page.screenshot({
        path: `${outDir}/${name}.png`,
        fullPage: fullPageScreenshots,
    })
    console.log(`wrote ${name}.png`)
}

async function optionalShot(label, callback) {
    try {
        await callback()
    } catch (error) {
        console.warn(`Skipping ${label}: ${error.message}`)
    }
}

// 1. Login
await page.goto(`${ADMIN_URL}/login`)
await page.getByLabel(/email/i).fill(ADMIN_EMAIL)
await page.getByRole('textbox', { name: /password/i }).fill(ADMIN_PASSWORD)
await page.getByRole('button', { name: /sign in/i }).click()
await page.waitForLoadState('networkidle')
await page
    .getByText(/dashboard/i)
    .first()
    .waitFor()

// 2. Pages list
await page.goto(`${ADMIN_URL}/pages`)
await shot('01-pages-list', 'table')

// 3. Page edit/create flow
await optionalShot('page editor screenshots', async () => {
    await page
        .locator('table tbody tr')
        .first()
        .getByRole('link')
        .first()
        .click()
    await page.waitForLoadState('networkidle')
    await page
        .getByRole('button', { name: /save as draft|save changes|save/i })
        .first()
        .scrollIntoViewIfNeeded()
    await shot('02-edit-page-save-as-draft')

    await page
        .getByRole('button', { name: /save as draft|save changes|save/i })
        .first()
        .hover()
    await page.waitForTimeout(800)
    await shot('03-edit-page-save-as-draft-tooltip')

    await page.goto(`${ADMIN_URL}/pages/create`)
    await page.waitForLoadState('networkidle')
    await page
        .getByRole('button', { name: /save as draft|save changes|save/i })
        .first()
        .scrollIntoViewIfNeeded()
    await shot('04-create-page-save-as-draft')
})

// 5. Media list and upload/edit flow
await optionalShot('media screenshots', async () => {
    await page.goto(`${ADMIN_URL}/media`)
    await page.waitForLoadState('networkidle')
    await shot('05-media-list', 'table')

    const uploadButton = page
        .getByRole('button', { name: /new|create|upload/i })
        .first()

    if ((await uploadButton.count()) > 0) {
        await uploadButton.click()
        await page.waitForLoadState('networkidle')
        await shot('06-media-upload-dialog')
        await page.keyboard.press('Escape')
    }

    const manageMediaLink = page
        .getByRole('link', { name: /manage media/i })
        .first()

    if ((await manageMediaLink.count()) > 0) {
        await manageMediaLink.click()
    } else {
        await page
            .locator('table tbody tr')
            .first()
            .getByRole('link')
            .first()
            .click()
    }

    await page.waitForLoadState('networkidle')
    await page
        .getByText(/focal point|localized metadata|alt text/i)
        .first()
        .scrollIntoViewIfNeeded()
    await shot('07-media-edit-focal-point')

    await page.getByRole('tab', { name: /metadata/i }).click()
    await page.waitForTimeout(500)
    await page
        .getByText(/localized metadata|alt text/i)
        .first()
        .scrollIntoViewIfNeeded()
    await shot('08-media-edit-localized-metadata')

    const doctorImageAction = page.getByRole('button', {
        name: /doctor image/i,
    })

    if ((await doctorImageAction.count()) > 0) {
        await doctorImageAction.first().click()
        await page.waitForSelector('[role="dialog"]')
        await shot('09-media-ai-doctor-image')
        await page.keyboard.press('Escape')
    }
})

// 6. Frontend media rendering surface
await optionalShot('frontend media screenshot', async () => {
    await page.goto(FRONTEND_URL)
    await page.waitForLoadState('networkidle')

    const firstImage = page.locator('img').first()
    if ((await firstImage.count()) > 0) {
        await firstImage.scrollIntoViewIfNeeded()
    }

    await shot('10-frontend-media-rendering')
})

// 7. PublishingStudio list (requires WorkspaceResource registered in the host panel)
try {
    await page.goto(`${ADMIN_URL}/publishing-studio`)
    await page.waitForLoadState('networkidle')
    await shot('11-publishing-studio-list', 'table')

    // 6. Request Changes modal
    await page
        .getByRole('button', { name: /request changes/i })
        .first()
        .click()
    await page.waitForSelector('textarea[placeholder*="specific"]')
    await shot('12-request-changes-modal')
    await page.keyboard.press('Escape')

    // 7. Approval History panel on workspace edit
    await page
        .getByRole('link', { name: /edit|open/i })
        .first()
        .click()
    await page.waitForLoadState('networkidle')
    await page.getByText(/approval history/i).scrollIntoViewIfNeeded()
    await shot('13-approval-history-panel')
} catch (err) {
    console.warn(`Skipping workspace screenshots: ${err.message}`)
    console.warn(
        'Register WorkspaceResource in your AdminPanelProvider to enable.',
    )
}

await browser.close()
console.log(`\nDone. Screenshots written to ${outDir}`)
