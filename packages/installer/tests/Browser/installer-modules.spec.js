const path = require('path')
const { expect, test } = require('@playwright/test')

const installerScripts = [
    'install/support.js',
    'install/wizard.js',
    'install/packages.js',
    'install/form-options.js',
    'install/progress.js',
    'install/csrf.js',
    'install/runner.js',
    'install.js',
]

const scriptsRoot = path.resolve(__dirname, '../../resources/js')

async function addInstallerScripts(page, scripts = installerScripts) {
    for (const script of scripts) {
        await page.addScriptTag({ path: path.join(scriptsRoot, script) })
    }
}

async function setInstallerHarness(page) {
    await page.setContent(`
        <meta name="csrf-token" content="initial-token">
        <main>
            <div id="form-view">
                <div id="errors" hidden><ul id="errors-list"></ul></div>
                <form id="install-form" action="https://installer.test/install">
                    <input type="hidden" name="_token" value="initial-token">
                    <button type="button" data-step-trigger="readiness">Readiness</button>
                    <button type="button" data-step-trigger="site">Site</button>
                    <button type="button" data-step-trigger="packages">Packages</button>
                    <button type="button" data-step-trigger="options">Options</button>
                    <section data-installer-step="readiness"></section>
                    <section data-installer-step="site" hidden>
                        <div class="field" data-field="site_name">
                            <label>Site name</label>
                            <input name="site_name" required>
                            <span class="field-error"></span>
                        </div>
                    </section>
                    <section data-installer-step="packages" hidden>
                        <label>
                            <input type="checkbox" data-package-select-all="extensions">
                            <span data-package-select-all-label>Select all</span>
                        </label>
                        <div data-package-selection-list>
                            <label data-package-row="vendor/app"><strong>App</strong>
                                <input type="checkbox" name="packages[]" value="vendor/app" data-package-checkbox="vendor/app" data-package-extension="true">
                                <span data-required-badge="vendor/app" style="display:none"></span>
                            </label>
                            <label data-package-row="vendor/middle"><strong>Middle</strong>
                                <input type="checkbox" name="packages[]" value="vendor/middle" data-package-checkbox="vendor/middle" data-package-extension="true">
                                <span data-required-badge="vendor/middle" style="display:none"></span>
                            </label>
                            <label data-package-row="vendor/base"><strong>Base</strong>
                                <input type="checkbox" name="packages[]" value="vendor/base" data-package-checkbox="vendor/base" data-package-extension="true">
                                <span data-required-badge="vendor/base" style="display:none"></span>
                            </label>
                        </div>
                    </section>
                    <section data-installer-step="options" hidden></section>
                    <button type="button" data-step-back>Back</button>
                    <button type="button" data-step-continue>Continue</button>
                    <button id="submit-button" type="submit" hidden><span data-submit-label>Install Capell</span></button>
                </form>
            </div>
            <div id="progress-view">
                <div id="progress-status"><span id="progress-status-label"></span></div>
                <div id="progress-loader"></div>
                <div id="current-step-strip"><span id="current-step-name"></span></div>
                <div id="failure-panel" hidden><span id="failure-title"></span><span id="failure-message"></span></div>
                <details id="technical-log-panel"><div id="log"></div></details>
                <div id="progress-steps"></div>
                <a id="admin-link"></a><a id="back-link" hidden></a>
                <form id="report-link"></form>
                <button data-report-download-button hidden></button>
            </div>
            <div data-flow-step="readiness"></div><div data-flow-step="site"></div>
            <div data-flow-step="packages"></div><div data-flow-step="options"></div>
            <div data-flow-step="installing"></div>
            <span data-summary-package-count></span>
            <script id="capell-installer-config" type="application/json">${JSON.stringify(
                {
                    requirementsMap: {
                        'vendor/app': ['vendor/middle'],
                        'vendor/middle': ['vendor/base'],
                        'vendor/base': [],
                    },
                    messages: {
                        requiredByPackages: 'Required by :packages',
                        packageSelectAll: 'Select all',
                        packageUnselectAll: 'Unselect all',
                        submitLabel: 'Install Capell',
                        installPackageLabel: 'Install __count__ package',
                        installPackagesLabel: 'Install __count__ packages',
                        installingPackageLabel: 'Installing __count__ package',
                        installingPackagesLabel:
                            'Installing __count__ packages',
                        sessionExpired: 'Session expired',
                        unknownError: 'Unknown error',
                        networkError: 'Network error',
                    },
                },
            )}</script>
        </main>
    `)

    await addInstallerScripts(page)
}

async function continueToPackages(page) {
    await page.locator('[data-step-continue]').click()
    await page.locator('input[name="site_name"]').fill('Capell')
    await page.locator('[data-step-continue]').click()
    await expect(page.locator('[data-installer-step="packages"]')).toBeVisible()
}

test('package dependencies, form inputs, select-all, and labels are observable', async ({
    page,
}) => {
    await setInstallerHarness(page)

    const app = page.locator('[data-package-checkbox="vendor/app"]')
    const middle = page.locator('[data-package-checkbox="vendor/middle"]')
    const base = page.locator('[data-package-checkbox="vendor/base"]')
    const baseRequiredBadge = page.locator(
        '[data-required-badge="vendor/base"]',
    )
    const selectAll = page.locator('[data-package-select-all]')

    await continueToPackages(page)

    await app.check()
    await expect(middle).toBeChecked()
    await expect(middle).toBeDisabled()
    await expect(base).toBeChecked()
    await expect(base).toBeDisabled()
    await expect(
        page.locator('[data-required-hidden="vendor/base"]'),
    ).toHaveValue('vendor/base')
    await expect(baseRequiredBadge).toHaveText('Required by App')
    await expect(baseRequiredBadge).toBeVisible()
    await expect(selectAll).toHaveJSProperty('indeterminate', false)
    await expect(page.locator('[data-submit-label]')).toHaveText(
        'Install 3 packages',
    )

    await app.uncheck()
    await expect(middle).not.toBeChecked()
    await expect(middle).toBeEnabled()
    await expect(base).not.toBeChecked()
    await expect(base).toBeEnabled()
    await expect(
        page.locator('[data-required-hidden="vendor/base"]'),
    ).toHaveCount(0)
    await expect(baseRequiredBadge).toBeHidden()
    await expect(baseRequiredBadge).toHaveText('')

    await middle.check()
    await expect(selectAll).toHaveJSProperty('indeterminate', true)
    await selectAll.check()
    await expect(app).toBeChecked()
    await expect(selectAll).toBeChecked()
    await selectAll.uncheck()
    await expect(app).not.toBeChecked()
    await expect(middle).not.toBeChecked()
})

test('wizard blocks invalid input and Enter advances without submitting', async ({
    page,
}) => {
    await setInstallerHarness(page)

    await page.locator('[data-step-continue]').click()
    await expect(page.locator('[data-installer-step="site"]')).toBeVisible()
    await page.locator('[data-step-continue]').click()
    await expect(page.locator('input[name="site_name"]')).toHaveAttribute(
        'aria-invalid',
        'true',
    )
    await expect(page.locator('[data-installer-step="site"]')).toBeVisible()

    await page.locator('input[name="site_name"]').fill('Capell')
    await page.locator('input[name="site_name"]').press('Enter')
    await expect(page.locator('[data-installer-step="packages"]')).toBeVisible()
    await expect(page.locator('#progress-view')).not.toHaveClass(/active/)
})

test('wizard reveals matched fields and presents unmatched validation errors globally', async ({
    page,
}) => {
    await setInstallerHarness(page)

    await page.evaluate(() => {
        const wizard = window.CapellInstaller.createWizard({
            form: document.getElementById('install-form'),
        })

        wizard.showFieldErrors({
            site_name: ['The site name is invalid.'],
            cache_store: ['The configured cache store is unavailable.'],
        })
    })

    await expect(page.locator('[data-installer-step="site"]')).toBeVisible()
    await expect(page.locator('input[name="site_name"]')).toHaveAttribute(
        'aria-invalid',
        'true',
    )
    await expect(page.locator('#errors')).toBeVisible()
    await expect(page.locator('#errors-list')).toContainText(
        'The configured cache store is unavailable.',
    )
})

async function setRunnerHarness(page) {
    await page.setContent(`
        <meta name="csrf-token" content="initial-token">
        <form id="install-form" action="https://installer.test/install">
            <input type="hidden" name="_token" value="initial-token">
        </form>
        <button id="submit-button"><span data-submit-label></span></button>
        <form id="report-link" action="https://installer.test/report"></form>
    `)
    await addInstallerScripts(page, [
        'install/support.js',
        'install/csrf.js',
        'install/runner.js',
    ])
}

test('runner reports malformed submit responses as failures', async ({
    page,
}) => {
    await setRunnerHarness(page)

    const result = await page.evaluate(async () => {
        const errors = []
        window.fetch = async () =>
            new Response('<html>broken</html>', {
                status: 500,
                statusText: 'Server Error',
            })
        const runner = window.CapellInstaller.createInstallRunner({
            form: document.getElementById('install-form'),
            wizard: {
                currentStep: () => 'options',
                setFlowStep: () => {},
                showGlobalError: (message) => errors.push(message),
            },
            packages: { updateSubmitButtonLabel: () => {} },
            progress: { showFormView: () => {} },
            csrf: { token: () => 'initial-token', setToken: () => {} },
            messages: { unknownError: 'Unknown error' },
        })

        await runner.submitInstallForm(false)
        return errors
    })

    expect(result).toEqual(['HTTP 500 Server Error'])
})

test('runner classifies timeout html without rendering the raw response', async ({
    page,
}) => {
    await setRunnerHarness(page)

    const errors = await page.evaluate(async () => {
        const messages = []
        window.fetch = async () =>
            new Response('<html><h1>504 Gateway Timeout</h1></html>', {
                status: 504,
                statusText: 'Gateway Timeout',
            })
        const runner = window.CapellInstaller.createInstallRunner({
            form: document.getElementById('install-form'),
            wizard: {
                currentStep: () => 'options',
                setFlowStep: () => {},
                showGlobalError: (message) => messages.push(message),
            },
            packages: { updateSubmitButtonLabel: () => {} },
            progress: { showFormView: () => {} },
            csrf: { token: () => 'initial-token', setToken: () => {} },
            messages: {
                serverTimeoutError: 'The installation request timed out.',
                unknownError: 'Unknown error',
            },
        })

        await runner.submitInstallForm(false)
        return messages
    })

    expect(errors).toEqual(['The installation request timed out.'])
    expect(errors.join(' ')).not.toContain('<html>')
    expect(errors.join(' ')).not.toContain('Gateway Timeout')
})

test('progress renders step windows, timers, and report configuration', async ({
    page,
}) => {
    await setInstallerHarness(page)

    await page.evaluate(() => {
        let now = 1000
        const originalNow = Date.now
        Date.now = () => now
        const progress = window.CapellInstaller.createProgress({
            messages: {
                progressCompletedSteps: '__completed__ of __total__ complete',
                progressPreviousStep: 'Previous',
                progressCurrentStep: 'Current',
                progressNextStep: 'Next',
            },
        })
        progress.renderPlanSteps([
            { key: 'preflight', label: 'Preflight' },
            { key: 'database', label: 'Database' },
            { key: 'assets', label: 'Assets' },
            { key: 'cache', label: 'Cache' },
        ])
        progress.startStepTimer('preflight')
        now = 61000
        progress.completeStep('preflight')
        progress.markStepStatus('database')
        progress.configureReport('/install/report', 'install-123')
        Date.now = originalNow
    })

    await expect(
        page.locator('[data-step-window-state="previous"] select'),
    ).toHaveValue('preflight')
    await expect(
        page.locator('[data-step-key="preflight"] .duration'),
    ).toHaveText('1m 00s')
    await expect(page.locator('[data-step-key="database"]')).toHaveClass(
        /active/,
    )
    await expect(
        page.locator('[data-step-window-state="next"] select'),
    ).toHaveValue('assets')
    await page
        .locator('[data-step-window-state="next"] select')
        .selectOption('cache')
    await expect(
        page.locator('[data-step-window-state="next"]'),
    ).toHaveAttribute('data-step-key', 'cache')
    await expect(page.locator('[data-progress-steps-count]')).toHaveText(
        '1 of 4 complete',
    )
    await expect(page.locator('#report-link')).toHaveJSProperty('hidden', false)
    await expect(page.locator('#report-link')).toHaveAttribute(
        'action',
        '/install/report',
    )
    await expect(page.locator('[data-report-download-button]')).toBeVisible()
    await expect(page.locator('[data-report-download-button]')).toHaveAttribute(
        'data-download-filename',
        'capell-install-install-123.json',
    )
})

test('failed steps render error and remediation in the progress UI', async ({
    page,
}) => {
    await setInstallerHarness(page)

    await page.evaluate(() => {
        window.fetch = async () =>
            new Response(
                JSON.stringify({
                    status: 'failed',
                    error: 'Database unavailable',
                    remediation: 'Check the database connection.',
                    lines: [{ line: 'Opening database', type: 'output' }],
                }),
                { status: 500 },
            )
        const progress = window.CapellInstaller.createProgress({
            messages: {
                installationProblemMessage: 'There is a problem with :step.',
                unknownError: 'Unknown error',
                statuses: { running: 'Running', failed: 'Failed' },
            },
        })
        progress.renderPlanSteps([{ key: 'database', label: 'Build database' }])
        const runner = window.CapellInstaller.createInstallRunner({
            form: document.getElementById('install-form'),
            wizard: {},
            packages: { updateSubmitButtonLabel: () => {} },
            progress,
            csrf: { token: () => 'token', setToken: () => {} },
            messages: {},
        })
        runner.runNextStep('install-1', '/run-step', 'database')
    })

    await expect(page.locator('#progress-status')).toHaveClass(/failed/)
    await expect(page.locator('#failure-panel')).toBeVisible()
    await expect(page.locator('#failure-title')).toHaveText(
        'There is a problem with build database.',
    )
    await expect(page.locator('#log')).toContainText('Database unavailable')
    await expect(page.locator('#log')).toContainText(
        'Fix: Check the database connection.',
    )
    await expect(page.locator('#technical-log-panel')).toHaveJSProperty(
        'open',
        true,
    )
    await expect(page.locator('#back-link')).toHaveJSProperty('hidden', false)
})

test('submit refreshes a 419 token and retries exactly once', async ({
    page,
}) => {
    await setRunnerHarness(page)

    const requests = await page.evaluate(async () => {
        const calls = []
        window.fetch = async (url, options) => {
            calls.push({
                url,
                method: options.method,
                token: options.headers['X-CSRF-TOKEN'],
            })
            if (options.method === 'GET') {
                return new Response(
                    '<meta name="csrf-token" content="fresh-token">',
                    {
                        status: 200,
                    },
                )
            }
            if (calls.filter((call) => call.method === 'POST').length === 1) {
                return new Response('{}', { status: 419 })
            }
            return new Response(
                JSON.stringify({
                    installId: 'install-1',
                    runStepUrl: '/run-step',
                }),
                { status: 200 },
            )
        }
        const form = document.getElementById('install-form')
        const csrf = window.CapellInstaller.createCsrf({ form })
        const runner = window.CapellInstaller.createInstallRunner({
            form,
            wizard: {
                currentStep: () => 'options',
                setFlowStep: () => {},
                showGlobalError: () => {},
            },
            packages: { updateSubmitButtonLabel: () => {} },
            progress: {
                showFormView: () => {},
                renderPlanSteps: () => {},
                configureReport: () => {},
                appendLogLine: () => {},
                setStatus: () => {},
                startStepTimer: () => {},
                markStepStatus: () => {},
                completeStep: () => {},
                showInstalledPanel: () => {},
            },
            csrf,
            messages: { sessionExpired: 'Session expired' },
        })

        await runner.submitInstallForm(false)
        return calls
    })

    expect(requests.filter((request) => request.method === 'POST')).toEqual([
        expect.objectContaining({ token: 'initial-token' }),
        expect.objectContaining({ token: 'fresh-token' }),
    ])
    expect(requests.filter((request) => request.method === 'GET')).toHaveLength(
        1,
    )
})

test('submit restores the form when csrf refresh returns no token', async ({
    page,
}) => {
    await setRunnerHarness(page)

    const result = await page.evaluate(async () => {
        const events = []
        window.fetch = async () => new Response('{}', { status: 419 })
        const runner = window.CapellInstaller.createInstallRunner({
            form: document.getElementById('install-form'),
            wizard: {
                currentStep: () => 'options',
                setFlowStep: (step) => events.push(['step', step]),
                showGlobalError: (message) => events.push(['error', message]),
            },
            packages: { updateSubmitButtonLabel: () => {} },
            progress: {
                showFormView: () => events.push(['view', 'form']),
            },
            csrf: {
                token: () => 'expired-token',
                setToken: () => {},
                refresh: async () => '',
            },
            messages: { sessionExpired: 'Session expired' },
        })

        runner.setSubmitting(true)
        await runner.submitInstallForm(false)

        return {
            disabled: document.getElementById('submit-button').disabled,
            events,
        }
    })

    expect(result.disabled).toBe(false)
    expect(result.events).toEqual(
        expect.arrayContaining([
            ['view', 'form'],
            ['step', 'options'],
            ['error', 'Session expired'],
        ]),
    )
})

test('run-step propagates csrf and treats 419 as a hard session failure', async ({
    page,
}) => {
    await setRunnerHarness(page)

    const result = await page.evaluate(async () => {
        const events = []
        let requestToken = null
        let resolveFailure
        const failureShown = new Promise((resolve) => {
            resolveFailure = resolve
        })
        window.fetch = async (url, options) => {
            requestToken = options.headers['X-CSRF-TOKEN']
            return new Response(JSON.stringify({ csrfToken: 'server-token' }), {
                status: 419,
            })
        }
        const runner = window.CapellInstaller.createInstallRunner({
            form: document.getElementById('install-form'),
            wizard: {},
            packages: { updateSubmitButtonLabel: () => {} },
            progress: {
                backLink: document.createElement('a'),
                setStatus: (status) => events.push(status),
                startStepTimer: () => {},
                finishStepTimer: () => {},
                markStepStatus: () => {},
                showFailurePanel: () => {
                    events.push('failure')
                    resolveFailure()
                },
                appendLogLine: (line) => events.push(line),
            },
            csrf: {
                token: () => 'step-token',
                setToken: (token) => events.push(token),
            },
            messages: { sessionExpired: 'Session expired' },
        })

        runner.runNextStep('install-1', '/run-step', 'database')
        await failureShown
        return { events, requestToken }
    })

    expect(result.requestToken).toBe('step-token')
    expect(result.events).toEqual(
        expect.arrayContaining([
            'server-token',
            'failed',
            'failure',
            '✗ Session expired',
        ]),
    )
})

test('runner safely completes a running step with no next step', async ({
    page,
}) => {
    await setRunnerHarness(page)

    const result = await page.evaluate(async () => {
        const events = []
        let requestCount = 0
        let resolveInstalled
        const installed = new Promise((resolve) => {
            resolveInstalled = resolve
        })
        const responses = [
            {
                installId: 'install-1',
                runStepUrl: '/run-step',
                nextStep: 'finish',
            },
            { status: 'running', lines: [] },
        ]
        window.fetch = async () => {
            requestCount += 1

            return new Response(JSON.stringify(responses.shift()), {
                status: 200,
            })
        }
        const runner = window.CapellInstaller.createInstallRunner({
            form: document.getElementById('install-form'),
            wizard: {
                completeInstallingFlow: () => events.push('flow-complete'),
            },
            packages: { updateSubmitButtonLabel: () => {} },
            progress: {
                renderPlanSteps: () => {},
                configureReport: () => {},
                appendLogLine: () => {},
                setStatus: (status) => events.push(status),
                startStepTimer: () => {},
                markStepStatus: () => {},
                renderLines: () => {},
                completeStep: () => events.push('step-complete'),
                showInstalledPanel: () => {
                    events.push('installed')
                    resolveInstalled()
                },
            },
            csrf: {
                token: () => 'token',
                setToken: (token) => events.push(token),
            },
            messages: {},
        })

        runner.submitInstallForm(false)
        await installed
        return { events, requestCount }
    })

    expect(result.requestCount).toBe(2)
    expect(result.events).toContain('step-complete')
    expect(result.events).toContain('complete')
    expect(result.events).toContain('flow-complete')
    expect(result.events).toContain('installed')
})

test('runner uses the active success url after a valid completion', async ({
    page,
}) => {
    await setRunnerHarness(page)

    const result = await page.evaluate(async () => {
        const events = []
        let requestCount = 0
        const responses = [
            {
                installId: 'install-1',
                runStepUrl: '/run-step',
                successUrl: '#installed',
                nextStep: 'finish',
            },
            { status: 'complete', lines: [], csrfToken: 'completed-token' },
        ]
        window.fetch = async () => {
            requestCount += 1

            return new Response(JSON.stringify(responses.shift()), {
                status: 200,
            })
        }
        const runner = window.CapellInstaller.createInstallRunner({
            form: document.getElementById('install-form'),
            wizard: { completeInstallingFlow: () => {} },
            packages: { updateSubmitButtonLabel: () => {} },
            progress: {
                renderPlanSteps: () => {},
                configureReport: () => {},
                appendLogLine: () => {},
                setStatus: () => {},
                startStepTimer: () => {},
                markStepStatus: () => {},
                renderLines: () => {},
                completeStep: () => events.push('step-complete'),
                showInstalledPanel: () => {},
            },
            csrf: {
                token: () => 'token',
                setToken: (token) => events.push(token),
            },
            messages: {},
        })

        await new Promise((resolve) => {
            runner.submitInstallForm(false)
            const poll = () => {
                if (window.location.hash === '#installed') {
                    resolve()
                    return
                }
                window.requestAnimationFrame(poll)
            }
            window.requestAnimationFrame(poll)
        })
        return { events, requestCount }
    })

    await expect
        .poll(() => page.evaluate(() => window.location.hash))
        .toBe('#installed')

    expect(result.requestCount).toBe(2)
    expect(result.events).toContain('completed-token')
    expect(result.events).toContain('step-complete')
})

test('downloaded dependencies refresh progress labels and totals without losing completed steps on retry', async ({
    page,
}) => {
    await setInstallerHarness(page)
    const result = await page.evaluate(async () => {
        const initialPlan = [
            { key: 'download', label: 'Download theme' },
            { key: 'theme', label: 'Install theme' },
            { key: 'finish', label: 'Finish' },
        ]
        const refreshedPlan = [
            initialPlan[0],
            { key: 'layout', label: 'Install Layout Builder' },
            initialPlan[1],
            { key: 'layout-after', label: 'Post-install Layout Builder' },
            initialPlan[2],
        ]
        const observations = []
        let failHook = true
        let resolveFailure
        let resolveComplete
        const failed = new Promise((resolve) => {
            resolveFailure = resolve
        })
        const complete = new Promise((resolve) => {
            resolveComplete = resolve
        })
        const progress = window.CapellInstaller.createProgress({ messages: {} })
        progress.renderPlanSteps(initialPlan)
        observations.push(
            document.querySelector('[data-progress-steps-count]').textContent,
        )
        const originalFailure = progress.showFailurePanel
        progress.showFailurePanel = (key) => {
            originalFailure(key)
            resolveFailure()
        }
        progress.showInstalledPanel = () => resolveComplete()
        window.fetch = async (url, options) => {
            const key = JSON.parse(options.body).step
            observations.push({
                key,
                count: document.querySelector('[data-progress-steps-count]')
                    .textContent,
                label: document.getElementById('current-step-name').textContent,
            })
            if (key === 'download')
                return new Response(
                    JSON.stringify({
                        status: 'running',
                        plan: refreshedPlan,
                        nextStep: 'layout',
                    }),
                )
            if (key === 'layout-after' && failHook) {
                failHook = false
                return new Response(
                    JSON.stringify({
                        status: 'failed',
                        error: 'Controlled hook failure',
                    }),
                    { status: 500 },
                )
            }
            const index = refreshedPlan.findIndex((step) => step.key === key)
            return new Response(
                JSON.stringify({
                    status: 'running',
                    nextStep: refreshedPlan[index + 1]?.key,
                }),
            )
        }
        const runner = window.CapellInstaller.createInstallRunner({
            form: document.getElementById('install-form'),
            wizard: { completeInstallingFlow: () => {} },
            packages: { updateSubmitButtonLabel: () => {} },
            progress,
            csrf: { token: () => 'token', setToken: () => {} },
        })
        runner.runNextStep('install-1', '/run-step', 'download')
        await failed
        const failureTitle =
            document.getElementById('failure-title').textContent
        runner.runNextStep('install-1', '/run-step', 'layout-after')
        await complete
        return {
            observations,
            failureTitle,
            finalCount: document.querySelector('[data-progress-steps-count]')
                .textContent,
        }
    })

    expect(result.observations[0]).toBe('0 of 3 complete')
    expect(result.observations.filter((item) => item.key === 'layout')).toEqual(
        [
            {
                key: 'layout',
                count: '1 of 5 complete',
                label: 'Install Layout Builder',
            },
        ],
    )
    expect(
        result.observations.filter((item) => item.key === 'layout-after'),
    ).toEqual([
        {
            key: 'layout-after',
            count: '3 of 5 complete',
            label: 'Post-install Layout Builder',
        },
        {
            key: 'layout-after',
            count: '3 of 5 complete',
            label: 'Post-install Layout Builder',
        },
    ])
    expect(result.observations.find((item) => item.key === 'theme').count).toBe(
        '2 of 5 complete',
    )
    expect(result.failureTitle).toBe(
        'There is a problem with post-install Layout Builder. See the log.',
    )
    expect(result.finalCount).toBe('5 of 5 complete')
})

test('runner reviews settings before execution and invalidates acceptance after edits', async ({
    page,
}) => {
    await setRunnerHarness(page)
    await page.locator('#install-form').evaluate((form) => {
        form.insertAdjacentHTML(
            'beforeend',
            `
            <input name="site_url" value="https://example.test">
            <section id="installation-review" hidden tabindex="-1">
                <dl data-installation-review-items></dl>
                <input type="checkbox" name="installation_confirmed" value="1" data-installation-confirmed>
                <input type="hidden" name="review_token" value="">
            </section>
        `,
        )
    })
    await page.evaluate(() => {
        window.reviewEvents = []
        window.reviewRequests = []
        window.fetch = async (url, options) => {
            const input = Object.fromEntries(options.body.entries())
            window.reviewRequests.push(input)
            return new Response(
                JSON.stringify(
                    input.review_only
                        ? {
                              review: {
                                  items: {
                                      Packages: 'vendor/example',
                                      Site: '<script>window.unsafeReview = true</script>',
                                  },
                                  token: 'review-token',
                              },
                          }
                        : { message: 'Test execution blocked', errors: {} },
                ),
                { status: input.review_only ? 200 : 422 },
            )
        }
        const runner = window.CapellInstaller.createInstallRunner({
            form: document.getElementById('install-form'),
            wizard: {
                beginInstallingFlow: () => window.reviewEvents.push('execute'),
                currentStep: () => 'options',
                setFlowStep: () => {},
                showFieldErrors: () => {},
                showGlobalError: (message) => window.reviewEvents.push(message),
            },
            packages: { updateSubmitButtonLabel: () => {} },
            progress: {
                reset: () => {},
                showProgressView: () => {},
                showFormView: () => {},
                setStatus: () => {},
            },
            csrf: {
                token: () => 'token',
                setToken: () => {},
                refresh: async () => 'token',
            },
            messages: {
                reviewInstallButton: 'Install with these settings',
                reviewRequired: 'Confirm the review',
            },
        })
        document
            .getElementById('submit-button')
            .addEventListener('click', () => runner.start())
    })
    await page.locator('#submit-button').click()
    await expect(page.locator('#installation-review')).toBeVisible()
    await expect(
        page.locator('[data-installation-review-items]'),
    ).toContainText('vendor/example')
    await expect(
        page.locator('[data-installation-review-items]'),
    ).toContainText('<script>')
    expect(await page.evaluate(() => window.unsafeReview)).toBeUndefined()
    expect(await page.evaluate(() => window.reviewEvents)).toEqual([])
    await page.locator('#submit-button').click()
    expect(await page.evaluate(() => window.reviewEvents)).toEqual([
        'Confirm the review',
    ])
    expect(await page.evaluate(() => window.reviewRequests)).toHaveLength(1)
    await page.locator('[data-installation-confirmed]').check()
    await page.locator('[name="site_url"]').fill('https://changed.test')
    await expect(page.locator('#installation-review')).toBeHidden()
    await expect(page.locator('[name="review_token"]')).toHaveValue('')
    await expect(
        page.locator('[data-installation-confirmed]'),
    ).not.toBeChecked()
    await page.locator('#submit-button').click()
    await expect(page.locator('#installation-review')).toBeVisible()
    await page.locator('[data-installation-confirmed]').check()
    await page.locator('#submit-button').click()
    await expect
        .poll(() => page.evaluate(() => window.reviewRequests.length))
        .toBe(3)
    expect(await page.evaluate(() => window.reviewRequests[2])).toMatchObject({
        site_url: 'https://changed.test',
        review_token: 'review-token',
        installation_confirmed: '1',
    })
    expect(await page.evaluate(() => window.reviewEvents)).toContain('execute')
})

test('runner discards a review when settings change while it is loading', async ({
    page,
}) => {
    await setRunnerHarness(page)
    await page.locator('#install-form').evaluate((form) => {
        form.insertAdjacentHTML(
            'beforeend',
            `
            <input name="site_url" value="https://example.test">
            <section id="installation-review" hidden tabindex="-1">
                <dl data-installation-review-items></dl>
                <input type="checkbox" data-installation-confirmed>
                <input name="review_token" value="">
            </section>
        `,
        )
    })
    await page.evaluate(() => {
        window.reviewErrors = []
        window.fetch = () =>
            new Promise((resolve) => {
                window.resolveReview = () =>
                    resolve(
                        new Response(
                            JSON.stringify({
                                review: {
                                    items: { Site: 'https://example.test' },
                                    token: 'stale-review',
                                },
                            }),
                            { status: 200 },
                        ),
                    )
            })
        const runner = window.CapellInstaller.createInstallRunner({
            form: document.getElementById('install-form'),
            wizard: {
                showGlobalError: (message) => window.reviewErrors.push(message),
            },
            packages: { updateSubmitButtonLabel: () => {} },
            progress: {},
            csrf: { token: () => 'token', refresh: async () => 'token' },
            messages: {
                reviewChanged: 'Review again',
                reviewLoading: 'Preparing review',
            },
        })
        runner.start()
    })
    await expect
        .poll(() => page.evaluate(() => typeof window.resolveReview))
        .toBe('function')
    await expect(page.locator('[data-submit-label]')).toHaveText(
        'Preparing review',
    )
    await page.locator('[name="site_url"]').fill('https://changed.test')
    await page.evaluate(() => window.resolveReview())
    await expect
        .poll(() => page.evaluate(() => window.reviewErrors))
        .toEqual(['Review again'])
    await expect(page.locator('#installation-review')).toBeHidden()
    await expect(page.locator('[name="review_token"]')).toHaveValue('')
})
