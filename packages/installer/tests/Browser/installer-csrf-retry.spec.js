const { expect, test } = require('@playwright/test')

const installerUrl =
    process.env.CAPELL_INSTALLER_URL || 'http://capell-ruby.test/install'

async function skipWhenUnreachable(page) {
    const response = await page.request
        .get(installerUrl, {
            failOnStatusCode: false,
            timeout: 15000,
        })
        .catch(() => null)

    if (response && response.status() < 500) {
        return
    }

    throw new Error(`CAPELL_INSTALLER_URL is unreachable: ${installerUrl}`)
}

test('installer refreshes a stale csrf token before submitting', async ({
    page,
}) => {
    const failedConsoleMessages = []
    const installResponses = []
    const installRequests = []
    const runStepRequests = []
    let installPlan = null

    page.on('console', (message) => {
        const text = message.text()

        if (text.includes('CSRF token mismatch')) {
            failedConsoleMessages.push(text)
        }
    })

    page.on('response', async (response) => {
        if (
            response.url() !== installerUrl ||
            response.request().method() !== 'POST'
        ) {
            return
        }

        installResponses.push(response.status())

        if (response.ok()) {
            installPlan = await response.json()
        }
    })

    await page.route('**/install/run-step', async (route) => {
        runStepRequests.push(route.request().postDataJSON())

        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                status: 'complete',
                lines: [],
                csrfToken: 'browser-test-token',
            }),
        })
    })

    await page.route(installerUrl, async (route) => {
        const request = route.request()

        if (request.method() === 'POST') {
            installRequests.push(request.postData() || '')
        }

        await route.continue()
    })

    await skipWhenUnreachable(page)

    await page.goto(installerUrl, { waitUntil: 'domcontentloaded' })

    await page.locator('meta[name="csrf-token"]').evaluate((element) => {
        element.setAttribute('content', 'stale-browser-test-token')
    })

    await page.locator('input[name="_token"]').evaluateAll((inputs) => {
        inputs.forEach((input) => {
            input.value = 'stale-browser-test-token'
        })
    })

    await expect(
        page.locator('[data-installer-step="readiness"]'),
    ).toBeVisible()

    await page.getByRole('button', { name: 'Continue' }).click()
    await expect(
        page.locator('[data-installer-step="site"]').first(),
    ).toBeVisible()
    await page.locator('input[name="new_user_name"]').fill('Admin')
    await page.locator('input[name="new_user_email"]').fill('admin@example.com')
    await page.locator('input[name="new_user_password"]').fill('password123')

    await page.getByRole('button', { name: 'Continue' }).click()
    await expect(
        page.locator('[data-installer-step="packages"]').first(),
    ).toBeVisible()

    await page.getByRole('button', { name: 'Continue' }).click()
    await expect(
        page.locator('[data-installer-step="options"]').first(),
    ).toBeVisible()

    await page.getByRole('button', { name: 'Install Capell' }).click()

    await expect
        .poll(() => installResponses, {
            message: 'installer POST should complete after refreshing csrf',
        })
        .toContain(200)

    expect(installResponses).not.toContain(419)
    expect(installRequests).toHaveLength(1)
    expect(installRequests[0]).toContain('name="new_user_email"')
    expect(installRequests[0]).toContain('admin@example.com')
    expect(installRequests[0]).toContain('name="package_selection_mode"')
    expect(installRequests[0]).toContain('core')
    expect(failedConsoleMessages).toEqual([])
    expect(installPlan).toMatchObject({
        status: 'pending',
        nextStep: 'preflight-checks',
    })
    expect(runStepRequests).toEqual([
        {
            install_id: installPlan.installId,
            step: 'preflight-checks',
        },
    ])

    if (installPlan?.cancelUrl && installPlan?.csrfToken) {
        await page.request.post(installPlan.cancelUrl, {
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': installPlan.csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            form: {
                _token: installPlan.csrfToken,
            },
        })
    }
})
