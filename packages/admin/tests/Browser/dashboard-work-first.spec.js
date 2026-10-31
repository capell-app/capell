import { expect, test } from '@playwright/test'
import { spawn, spawnSync } from 'node:child_process'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

// Runs against the real Testbench application and the canonical installed
// screenshot database, seeded with the record-state and metrics fixtures.
// Prepare a fresh database first: npm run test:dashboard-browser
const root = join(dirname(fileURLToPath(import.meta.url)), '../../../..')
const port = Number(process.env.CAPELL_DASHBOARD_BROWSER_PORT ?? 8157)
const origin = `http://127.0.0.1:${port}`

const environment = {
    ...process.env,
    PHPRC: undefined,
    PHP_INI_SCAN_DIR: `:${join(root, 'workbench/php')}`,
    APP_ENV: 'production',
    APP_DEBUG: 'false',
    APP_URL: origin,
    APP_KEY: 'base64:/MjiNkPfjAngJBfuMDsnFBxDynZGOKk3O6P0u0MhvJE=',
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: join(root, 'workbench/database/screenshots.sqlite'),
    CACHE_STORE: 'array',
    SESSION_DRIVER: 'file',
    QUEUE_CONNECTION: 'sync',
    DEBUGBAR_ENABLED: 'false',
    CAPELL_FRONTEND_PUBLIC_VIEW_QUERY_GUARD_ENABLED: 'false',
}

let server
const consoleErrors = []

test.use({
    launchOptions: {
        executablePath: process.env.CAPELL_BROWSER_EXECUTABLE || undefined,
    },
})

test.describe.configure({ mode: 'serial' })

test.beforeAll(async () => {
    // Seeding is not repeatable on one database; set this only when re-running
    // against a database a previous run already seeded.
    const seeded =
        process.env.CAPELL_DASHBOARD_BROWSER_SKIP_SEED === '1'
            ? { status: 0 }
            : spawnSync(
                  'php',
                  [
                      '-d',
                      'memory_limit=-1',
                      'vendor/bin/testbench',
                      'tinker',
                      '--execute',
                      'Workbench\\App\\Support\\RecordStateScreenshotFixture::initialize(); Workbench\\App\\Support\\SiteAdminMetricsScreenshotFixture::initialize();',
                  ],
                  { cwd: root, env: environment, encoding: 'utf8' },
              )

    expect(
        seeded.status,
        `Seeding failed; prepare a fresh database with npm run screenshots:prepare.\n${seeded.stdout}${seeded.stderr}`,
    ).toBe(0)

    server = spawn(
        'php',
        [
            '-d',
            'memory_limit=-1',
            'vendor/bin/testbench',
            'serve',
            '--host=127.0.0.1',
            `--port=${port}`,
            '--no-reload',
            '--no-interaction',
        ],
        { cwd: root, env: environment, stdio: 'ignore' },
    )

    for (let attempt = 0; attempt < 120; attempt++) {
        try {
            if ((await fetch(`${origin}/__ping`)).ok) {
                return
            }
        } catch {
            // The server is still starting.
        }

        await new Promise((resolve) => setTimeout(resolve, 500))
    }

    throw new Error('The Testbench server did not become ready.')
})

test.afterAll(() => {
    server?.kill()
})

async function signIn(page) {
    page.on('console', (message) => {
        if (message.type() === 'error') {
            consoleErrors.push(message.text())
        }
    })
    page.on('pageerror', (error) => consoleErrors.push(error.message))

    await page.goto(`${origin}/admin/login`)
    await page.getByLabel('Email address').fill('admin@example.com')
    await page.getByRole('textbox', { name: /password/i }).fill('password')
    await page.keyboard.press('Enter')
    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible()
}

async function expectNoHorizontalOverflow(page) {
    const overflow = await page.evaluate(
        () =>
            document.documentElement.scrollWidth -
            document.documentElement.clientWidth,
    )

    expect(overflow).toBeLessThanOrEqual(0)
}

test('shows the seeded work, attention and publishing cards on desktop', async ({
    page,
}) => {
    await page.setViewportSize({ width: 1280, height: 900 })
    await signIn(page)

    await expect(page.getByText('Your work', { exact: true })).toBeVisible()
    await expect(
        page.getByText('Site needs attention', { exact: true }).first(),
    ).toBeVisible()
    await expect(
        page.getByText('Publishing health', { exact: true }).first(),
    ).toBeVisible()
    await expect(
        page.getByText('Scheduled page without an active URL').first(),
    ).toBeVisible()
    await expect(page.getByLabel('Analytics period')).toBeVisible()
    await expectNoHorizontalOverflow(page)
    expect(consoleErrors).toEqual([])
})

test('keeps the populated dashboard inside a 390px viewport', async ({
    page,
}) => {
    await page.setViewportSize({ width: 390, height: 844 })
    await signIn(page)

    // Cards below the fold load as they scroll into view on a narrow screen.
    await page.mouse.wheel(0, 900)
    await expect(
        page.getByText('Site needs attention', { exact: true }).first(),
    ).toBeVisible()
    await page.mouse.wheel(0, 900)
    await expect(
        page.getByText('Publishing health', { exact: true }).first(),
    ).toBeVisible()
    await expectNoHorizontalOverflow(page)
})

test('saves a custom layout using the keyboard alone', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 })
    await signIn(page)

    await page.getByRole('button', { name: 'Edit dashboard' }).focus()
    await page.keyboard.press('Enter')

    const dialog = page.getByRole('dialog')
    await expect(dialog.getByText('Edit dashboard').first()).toBeVisible()

    const contentStatus = dialog.getByRole('checkbox', {
        name: /Content status/,
    })
    await contentStatus.focus()
    await expect(contentStatus).not.toBeChecked()
    await page.keyboard.press('Space')
    await expect(contentStatus).toBeChecked()

    await dialog.getByRole('button', { name: 'Save' }).focus()
    await page.keyboard.press('Enter')

    await expect(dialog).toBeHidden()
    await expect(page.getByText('Content status').first()).toBeVisible()

    await page.reload()
    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible()
    // Lower cards load as they scroll into view.
    await page.mouse.wheel(0, 1600)
    await expect(page.getByText('Content status').first()).toBeVisible()
})

for (const scheme of ['light', 'dark']) {
    test(`renders the dashboard in ${scheme} mode without errors`, async ({
        page,
    }) => {
        await page.emulateMedia({ colorScheme: scheme })
        await page.setViewportSize({ width: 1280, height: 900 })
        await signIn(page)

        await expect(
            page.getByText('Publishing health', { exact: true }).first(),
        ).toBeVisible()

        const dark = await page.evaluate(() =>
            document.documentElement.classList.contains('dark'),
        )
        expect(dark).toBe(scheme === 'dark')

        const [text, background] = await page.evaluate(() => {
            const heading = [...document.querySelectorAll('h1')].find((h) =>
                h.textContent?.includes('Dashboard'),
            )

            return [
                getComputedStyle(heading).color,
                getComputedStyle(document.body).backgroundColor,
            ]
        })
        expect(text).not.toBe(background)
        await expectNoHorizontalOverflow(page)
    })
}
