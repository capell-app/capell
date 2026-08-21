const test = require('node:test')
const assert = require('node:assert/strict')
const { spawnSync } = require('node:child_process')
const { EventEmitter } = require('node:events')
const { chromium } = require('playwright')
const fs = require('node:fs')
const os = require('node:os')
const path = require('node:path')

const {
    JourneyDiagnostics,
    redactHtmlEvidence,
    redactText,
    redactValue,
    sanitisePageHtml,
} = require('./failure-evidence')

test('structurally redacts CSRF and Livewire state from HTML evidence', () => {
    const redacted = redactHtmlEvidence(`
        <meta name="csrf-token" content="csrf-value">
        <input name="_token" value="csrf-input">
        <div wire:snapshot="{&quot;memo&quot;:{&quot;token&quot;:&quot;session-state&quot;}}" data-csrf="csrf-attribute"></div>
        <script>window.livewireScriptConfig = { token: "script-state" }</script>
    `)

    assert.doesNotMatch(
        redacted,
        /csrf-value|csrf-input|csrf-attribute|session-state|script-state/,
    )
    assert.match(redacted, /\[redacted\]/)
    assert.doesNotMatch(
        redacted,
        /wire:snapshot=.*session-state|livewireScriptConfig/,
    )
})

test('sanitises an authentic browser DOM before serialising evidence', async () => {
    const browser = await chromium.launch({ headless: true })

    try {
        const page = await browser.newPage()
        await page.setContent(`
            <meta name="csrf-token" content="csrf-value">
            <input name="_token" value="csrf-input">
            <div wire:snapshot="{&quot;stateToken&quot;:&quot;session-state&quot;}" data-csrf="csrf-attribute"></div>
            <script>window.livewireScriptConfig = { stateToken: "script-state" }</script>
            <main>CAP-0266 CI Editor</main>
        `)

        const redacted = await sanitisePageHtml(page, ['CAP-0266 CI Editor'])

        assert.doesNotMatch(
            redacted,
            /csrf-value|csrf-input|csrf-attribute|session-state|script-state|CAP-0266 CI Editor/,
        )
        assert.match(redacted, /\[redacted\]/)
    } finally {
        await browser.close()
    }
})

test('redacts journey credentials, signed query values, and cookies', () => {
    const text =
        'cap-0266-editor@example.test password=private Bearer abcdefghijk ' +
        '/preview?signature=signed-value&token=token-value Set-Cookie: session=abc ' +
        '{"stateToken":"state-private","csrfToken":"csrf-private"}'
    const redacted = redactText(text, [
        'cap-0266-editor@example.test',
        'private',
    ])

    assert.doesNotMatch(
        redacted,
        /cap-0266-editor|private|abcdefghijk|signed-value|token-value|session=abc|state-private|csrf-private/,
    )
    assert.match(redacted, /\[redacted\]/)
})

test('redacts values under diagnostic keys that can carry secrets', () => {
    assert.deepEqual(
        redactValue({
            url: 'https://example.test/public',
            authorization: 'Bearer private',
            nested: { apiKey: 'private-key', message: 'safe message' },
        }),
        {
            url: 'https://example.test/public',
            authorization: '[redacted]',
            nested: { apiKey: '[redacted]', message: 'safe message' },
        },
    )
})

test('creates the evidence directory before redacting backend logs', () => {
    const temporaryDirectory = fs.mkdtempSync(
        path.join(os.tmpdir(), 'capell-redacted-evidence-'),
    )
    const inputPath = path.join(temporaryDirectory, 'backend.log')
    const outputPath = path.join(
        temporaryDirectory,
        'missing',
        'backend-redacted.log',
    )

    fs.writeFileSync(
        inputPath,
        'password=private stateToken="state-private" csrfToken="csrf-private"\n',
    )

    const result = spawnSync(
        process.execPath,
        [path.join(__dirname, 'redact-log.js'), outputPath, inputPath],
        {
            encoding: 'utf8',
            env: {
                ...process.env,
                CAPELL_DIAGNOSTIC_SECRETS: JSON.stringify(['private']),
            },
        },
    )

    try {
        assert.equal(result.status, 0, result.stderr)
        assert.equal(
            fs.readFileSync(outputPath, 'utf8'),
            '## backend.log\npassword=[redacted] stateToken="[redacted]" csrfToken="[redacted]"\n',
        )
    } finally {
        fs.rmSync(temporaryDirectory, { recursive: true, force: true })
    }
})

test('correlates allowed response failures with their browser console errors', async () => {
    const diagnostics = new JourneyDiagnostics({
        artifactDir: '/tmp/not-used',
        secretValues: [],
    })
    const page = new EventEmitter()
    page.url = () => 'https://example.test/draft'
    diagnostics.registerPage(page, 'anonymous')

    const response = (pathname) => ({
        status: () => 404,
        url: () => `https://example.test${pathname}`,
        request: () => ({ method: () => 'GET' }),
    })
    const consoleMessage = (pathname) => ({
        type: () => 'error',
        text: () =>
            'Failed to load resource: the server responded with a status of 404 (Not Found)',
        location: () => ({ url: `https://example.test${pathname}` }),
    })

    await diagnostics.step('expected public failures', async () => {
        diagnostics.allowResponseOnce({
            page,
            pathname: '/draft',
            status: 404,
        })
        diagnostics.allowResponseOnce({
            page,
            pathname: '/resources/css/app.css',
            status: 404,
        })

        page.emit('console', consoleMessage('/draft'))
        page.emit('response', response('/draft'))
        page.emit('response', response('/resources/css/app.css'))
        page.emit('console', consoleMessage('/resources/css/app.css'))

        assert.doesNotThrow(() => diagnostics.assertHealthy('expected 404s'))
    })

    page.emit('response', response('/unexpected.css'))

    assert.throws(
        () => diagnostics.assertHealthy('unexpected 404'),
        /observed 1 console, network, or backend failure/,
    )
})

test('only ignores ERR_ABORTED for navigation cancellations', () => {
    const diagnostics = new JourneyDiagnostics({
        artifactDir: '/tmp/not-used',
        secretValues: [],
    })
    const page = new EventEmitter()
    page.url = () => 'https://example.test/page'
    diagnostics.registerPage(page, 'anonymous')

    page.emit('requestfailed', {
        failure: () => ({ errorText: 'net::ERR_ABORTED' }),
        url: () => 'https://example.test/page',
        isNavigationRequest: () => true,
    })
    assert.equal(diagnostics.failures.length, 0)

    page.emit('requestfailed', {
        failure: () => ({ errorText: 'net::ERR_ABORTED' }),
        url: () => 'https://example.test/app.js',
        isNavigationRequest: () => false,
    })
    assert.equal(diagnostics.failures.length, 1)
})

test('ignores an allowed Livewire POST cancellation only after its successful redirect navigation', () => {
    const diagnostics = new JourneyDiagnostics({
        artifactDir: '/tmp/not-used',
        secretValues: [],
    })
    const page = new EventEmitter()
    const mainFrame = {}
    page.url = () => 'https://example.test/admin/pages/create'
    page.mainFrame = () => mainFrame
    diagnostics.registerPage(page, 'admin')
    diagnostics.allowLivewireRedirectOnce({
        page,
        destinationPathname: /^\/admin\/pages\/\d+\/edit$/,
    })

    const livewireRequest = {
        method: () => 'POST',
        failure: () => ({ errorText: 'net::ERR_ABORTED' }),
        url: () => 'https://example.test/livewire-59e7f451/update',
        isNavigationRequest: () => false,
    }
    page.emit('request', livewireRequest)
    page.emit('requestfailed', livewireRequest)

    assert.equal(diagnostics.failures.length, 0)

    const navigationRequest = {
        method: () => 'GET',
        frame: () => mainFrame,
        isNavigationRequest: () => true,
    }
    page.emit('response', {
        status: () => 200,
        url: () => 'https://example.test/admin/pages/5/edit',
        request: () => navigationRequest,
    })

    assert.doesNotThrow(() => diagnostics.assertHealthy('create draft'))
    assert.equal(diagnostics.failures.length, 0)
})

test('ignores the bound Livewire POST cancellation when the successful navigation response arrives first', () => {
    const diagnostics = new JourneyDiagnostics({
        artifactDir: '/tmp/not-used',
        secretValues: [],
    })
    const page = new EventEmitter()
    const mainFrame = {}
    page.url = () => 'https://example.test/admin/pages/create'
    page.mainFrame = () => mainFrame
    diagnostics.registerPage(page, 'admin')
    diagnostics.allowLivewireRedirectOnce({
        page,
        destinationPathname: /^\/admin\/pages\/\d+\/edit$/,
    })

    const livewireRequest = {
        method: () => 'POST',
        failure: () => ({ errorText: 'net::ERR_ABORTED' }),
        url: () => 'https://example.test/livewire-59e7f451/update',
        isNavigationRequest: () => false,
    }
    page.emit('request', livewireRequest)
    page.emit('response', {
        status: () => 200,
        url: () => 'https://example.test/admin/pages/5/edit',
        request: () => ({
            method: () => 'GET',
            frame: () => mainFrame,
            isNavigationRequest: () => true,
        }),
    })
    page.emit('requestfailed', livewireRequest)

    assert.doesNotThrow(() => diagnostics.assertHealthy('create draft'))
    assert.equal(diagnostics.failures.length, 0)
})

test('keeps allowed Livewire POST cancellations red without the expected successful navigation', () => {
    const diagnostics = new JourneyDiagnostics({
        artifactDir: '/tmp/not-used',
        secretValues: [],
    })
    const page = new EventEmitter()
    const mainFrame = {}
    page.url = () => 'https://example.test/admin/pages/create'
    page.mainFrame = () => mainFrame
    diagnostics.registerPage(page, 'admin')
    diagnostics.allowLivewireRedirectOnce({
        page,
        destinationPathname: /^\/admin\/pages\/\d+\/edit$/,
    })

    const livewireRequest = {
        method: () => 'POST',
        failure: () => ({ errorText: 'net::ERR_ABORTED' }),
        url: () => 'https://example.test/livewire-59e7f451/update',
        isNavigationRequest: () => false,
    }
    page.emit('request', livewireRequest)
    page.emit('requestfailed', livewireRequest)
    page.emit('response', {
        status: () => 200,
        url: () => 'https://example.test/admin/pages',
        request: () => ({
            method: () => 'GET',
            frame: () => mainFrame,
            isNavigationRequest: () => true,
        }),
    })

    assert.throws(
        () => diagnostics.assertHealthy('create draft'),
        /observed 1 console, network, or backend failure/,
    )
    assert.equal(diagnostics.failures.length, 1)
})

test('retains a repeatable page-bound allowance for stylesheet recovery retries', async () => {
    const diagnostics = new JourneyDiagnostics({
        artifactDir: '/tmp/not-used',
        secretValues: [],
    })
    const allowedPage = new EventEmitter()
    const otherPage = new EventEmitter()

    allowedPage.url = otherPage.url = () => 'https://example.test/page'
    diagnostics.registerPage(allowedPage, 'allowed-page')
    diagnostics.registerPage(otherPage, 'other-page')

    const response = {
        status: () => 404,
        url: () => 'https://example.test/resources/css/app.css',
        request: () => ({ method: () => 'GET' }),
    }
    const consoleMessage = {
        type: () => 'error',
        text: () =>
            'Failed to load resource: the server responded with a status of 404 (Not Found)',
        location: () => ({
            url: 'https://example.test/resources/css/app.css',
        }),
    }

    await diagnostics.step('page DOM checkpoint', async () => {
        diagnostics.allowResponse({
            page: allowedPage,
            pathname: '/resources/css/app.css',
            status: 404,
            repeat: true,
        })
    })

    allowedPage.emit('response', response)
    allowedPage.emit('console', consoleMessage)
    allowedPage.emit('response', response)
    allowedPage.emit('console', consoleMessage)
    assert.doesNotThrow(() => diagnostics.assertHealthy('delayed recovery'))

    otherPage.emit('response', response)
    assert.throws(
        () => diagnostics.assertHealthy('different page'),
        /observed 1 console, network, or backend failure/,
    )
})
