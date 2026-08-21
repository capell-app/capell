const fs = require('node:fs')
const path = require('node:path')

const SENSITIVE_KEY =
    /(?:authorization|cookie|credential|email|key|licen[cs]e|password|secret|signature|token)/i

function escapeRegExp(value) {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

function redactText(value, secretValues = []) {
    let redacted = String(value)

    for (const secret of secretValues) {
        const candidate = String(secret).trim()

        if (candidate !== '') {
            redacted = redacted.replace(
                new RegExp(escapeRegExp(candidate), 'gi'),
                '[redacted]',
            )
        }
    }

    return redacted
        .replace(/\bBearer\s+[A-Za-z0-9._~+/=-]+/gi, 'Bearer [redacted]')
        .replace(
            /([?&](?:expires|signature|token|access_token|refresh_token)=)[^&\s<>"']+/gi,
            '$1[redacted]',
        )
        .replace(
            /\b(password|passwd|pwd|secret|token|api[_-]?key|client[_-]?secret|authorization|cookie)\s*[:=]\s*([^\s,;]+)/gi,
            '$1=[redacted]',
        )
        .replace(
            /((?:["']?)(?:stateToken|csrfToken|signedState|signedEditorUrl|signedAdminUrl)(?:["']?)\s*:\s*)(["'])[^"']*\2/gi,
            '$1$2[redacted]$2',
        )
        .replace(/(set-cookie\s*:\s*)[^\r\n]+/gi, '$1[redacted]')
}

function redactValue(value, secretValues = [], key = '') {
    if (SENSITIVE_KEY.test(key)) {
        return '[redacted]'
    }

    if (Array.isArray(value)) {
        return value.map((item) => redactValue(item, secretValues))
    }

    if (value !== null && typeof value === 'object') {
        return Object.fromEntries(
            Object.entries(value).map(([entryKey, entryValue]) => [
                entryKey,
                redactValue(entryValue, secretValues, entryKey),
            ]),
        )
    }

    return typeof value === 'string' ? redactText(value, secretValues) : value
}

function safeUrl(value) {
    try {
        const parsed = new URL(value)

        return `${parsed.origin}${parsed.pathname}`
    } catch {
        return redactText(value)
    }
}

function redactHtmlEvidence(html, secretValues = []) {
    let redacted = redactText(html, secretValues)

    redacted = redacted
        .replace(
            /<input\b(?=[^>]*\bname\s*=\s*["']_token["'])[^>]*>/gi,
            '<input name="_token" value="[redacted]">',
        )
        .replace(
            /<meta\b(?=[^>]*\bname\s*=\s*["']csrf-token["'])[^>]*>/gi,
            '<meta name="csrf-token" content="[redacted]">',
        )
        .replace(
            /(\b(?:wire:snapshot|wire:effects|wire:initial-data|data-csrf)\s*=\s*)(["'])(?:(?!\2)[\s\S])*\2/gi,
            '$1$2[redacted]$2',
        )
        .replace(
            /<script\b[^>]*>[\s\S]*?livewireScriptConfig\s*=[\s\S]*?<\/script>/gi,
            '<script>[redacted Livewire config]</script>',
        )

    return redacted
}

async function sanitisePageHtml(page, secretValues = []) {
    const html = await page.evaluate(() => {
        const clone = document.documentElement.cloneNode(true)

        clone
            .querySelectorAll('meta[name="csrf-token"], input[name="_token"]')
            .forEach((element) => {
                const attribute =
                    element.localName === 'meta' ? 'content' : 'value'
                element.setAttribute(attribute, '[redacted]')
            })

        clone
            .querySelectorAll(
                '[wire\\:snapshot], [wire\\:effects], [wire\\:initial-data], [data-csrf]',
            )
            .forEach((element) => {
                for (const attribute of [
                    'wire:snapshot',
                    'wire:effects',
                    'wire:initial-data',
                    'data-csrf',
                ]) {
                    if (element.hasAttribute(attribute)) {
                        element.setAttribute(attribute, '[redacted]')
                    }
                }
            })

        clone.querySelectorAll('*').forEach((element) => {
            for (const attribute of [...element.attributes]) {
                if (
                    /(?:state[-_]?token|csrf[-_]?token|signed[-_]?state|signed[-_]?(?:editor|admin)[-_]?url)/i.test(
                        attribute.name,
                    )
                ) {
                    element.setAttribute(attribute.name, '[redacted]')
                }
            }
        })

        clone.querySelectorAll('script').forEach((script) => {
            if (
                /livewireScriptConfig|stateToken|csrfToken|signedState|signed(?:Editor|Admin)Url/i.test(
                    script.textContent ?? '',
                )
            ) {
                script.textContent = '[redacted Livewire state]'
            }
        })

        return `<!doctype html>\n${clone.outerHTML}`
    })

    return redactHtmlEvidence(html, secretValues)
}

class JourneyDiagnostics {
    constructor({ artifactDir, secretValues }) {
        this.artifactDir = artifactDir
        this.secretValues = secretValues
        this.failures = []
        this.pages = []
        this.trace = []
        this.allowedResponses = []
        this.allowedLivewireRedirects = []
    }

    registerPage(page, label) {
        this.pages.push({ page, label })

        page.on('console', (message) => {
            if (
                message.type() !== 'error' ||
                this.consumeAllowedConsole(message, page)
            ) {
                return
            }

            this.recordFailure(
                'console',
                label,
                message.text(),
                message.location().url || page.url(),
            )
        })

        page.on('pageerror', (error) => {
            this.recordFailure('pageerror', label, error.message, page.url())
        })

        page.on('request', (request) => {
            this.bindAllowedLivewireRedirectRequest(request, page)
        })

        page.on('requestfailed', (request) => {
            const message = request.failure()?.errorText ?? 'Request failed'

            if (
                message.includes('ERR_ABORTED') &&
                request.isNavigationRequest?.() === true
            ) {
                return
            }

            if (
                this.deferAllowedLivewireRedirectFailure(
                    request,
                    page,
                    label,
                    message,
                )
            ) {
                return
            }

            this.recordFailure('network', label, message, request.url())
        })

        page.on('response', (response) => {
            this.consumeAllowedLivewireRedirect(response, page)

            if (
                response.status() < 400 ||
                this.consumeAllowedResponse(response, page)
            ) {
                return
            }

            this.recordFailure(
                'backend',
                label,
                `${response.status()} ${response.request().method()}`,
                response.url(),
            )
        })
    }

    allowResponseOnce(options) {
        this.allowResponse(options)
    }

    allowResponse({ page, method = 'GET', pathname, status, repeat = false }) {
        if (!page) {
            throw new Error('Expected response allowances must be page-bound')
        }

        this.pruneAllowedResponses()
        this.allowedResponses.push({
            page,
            method,
            pathname,
            status,
            repeat,
            responseConsumed: false,
            consoleConsumed: false,
            expiresAt: Date.now() + (repeat ? 300_000 : 60_000),
        })
    }

    allowLivewireRedirectOnce({ page, destinationPathname }) {
        if (!page) {
            throw new Error('Expected Livewire redirects must be page-bound')
        }

        this.pruneAllowedLivewireRedirects()
        this.allowedLivewireRedirects.push({
            page,
            sourcePathname: new URL(page.url()).pathname,
            destinationPathname,
            request: null,
            failure: null,
            navigationSucceeded: false,
            expiresAt: Date.now() + 60_000,
        })
    }

    async step(label, callback) {
        const entry = {
            label,
            startedAt: new Date().toISOString(),
            status: 'running',
        }
        this.trace.push(entry)

        try {
            const result = await callback()
            entry.status = 'passed'

            return result
        } catch (error) {
            entry.status = 'failed'
            entry.error = redactText(
                error instanceof Error ? error.message : String(error),
                this.secretValues,
            )
            throw error
        } finally {
            entry.finishedAt = new Date().toISOString()
            this.pruneAllowedResponses()
        }
    }

    assertHealthy(checkpoint) {
        this.flushUnresolvedLivewireRedirectFailures()

        if (this.failures.length === 0) {
            return
        }

        const latest = this.failures[this.failures.length - 1]

        throw new Error(
            `${checkpoint} observed ${this.failures.length} console, network, or backend failure(s); latest: ${latest.type} ${latest.message}`,
        )
    }

    async captureFailure(testInfo) {
        this.flushUnresolvedLivewireRedirectFailures()
        fs.mkdirSync(this.artifactDir, { recursive: true })

        const diagnosticsPath = path.join(
            this.artifactDir,
            'browser-diagnostics.json',
        )
        const tracePath = path.join(this.artifactDir, 'journey-trace.json')

        fs.writeFileSync(
            diagnosticsPath,
            `${JSON.stringify(redactValue(this.failures, this.secretValues), null, 2)}\n`,
        )
        fs.writeFileSync(
            tracePath,
            `${JSON.stringify(redactValue(this.trace, this.secretValues), null, 2)}\n`,
        )

        await testInfo.attach('redacted-browser-diagnostics', {
            path: diagnosticsPath,
            contentType: 'application/json',
        })
        await testInfo.attach('redacted-journey-trace', {
            path: tracePath,
            contentType: 'application/json',
        })

        for (const { page, label } of this.pages) {
            if (page.isClosed()) {
                continue
            }

            const safeLabel = label.replace(/[^a-z0-9-]+/gi, '-').toLowerCase()
            const htmlPath = path.join(
                this.artifactDir,
                `${safeLabel}-redacted.html`,
            )
            const screenshotPath = path.join(
                this.artifactDir,
                `${safeLabel}-redacted.png`,
            )
            const html = await sanitisePageHtml(page, this.secretValues)

            fs.writeFileSync(htmlPath, html)
            await page.screenshot({
                path: screenshotPath,
                fullPage: true,
                mask: [
                    page.locator('input, textarea, [contenteditable="true"]'),
                    page.locator(
                        '.fi-user-menu, .capell-publish-status-panel, [aria-label*="account" i], [aria-label*="user" i]',
                    ),
                    ...this.secretValues
                        .filter((value) => String(value).trim() !== '')
                        .map((value) =>
                            page.getByText(String(value), { exact: false }),
                        ),
                ],
            })

            await testInfo.attach(`${safeLabel}-redacted-html`, {
                path: htmlPath,
                contentType: 'text/html',
            })
            await testInfo.attach(`${safeLabel}-redacted-screenshot`, {
                path: screenshotPath,
                contentType: 'image/png',
            })
        }
    }

    consumeAllowedResponse(response, page) {
        this.pruneAllowedResponses()
        const url = new URL(response.url())
        const allowed = this.allowedResponses.find(
            (allowed) =>
                (allowed.repeat || !allowed.responseConsumed) &&
                allowed.page === page &&
                allowed.status === response.status() &&
                allowed.method === response.request().method() &&
                allowed.pathname === url.pathname,
        )

        if (!allowed) {
            return false
        }

        if (!allowed.repeat) {
            allowed.responseConsumed = true
        }

        return true
    }

    consumeAllowedConsole(message, page) {
        this.pruneAllowedResponses()
        const statusMatch = message
            .text()
            .match(/Failed to load resource:.*status of (\d{3})/i)

        if (!statusMatch) {
            return false
        }

        const locationUrl = message.location().url || page.url()
        const pathname = new URL(locationUrl).pathname
        const status = Number(statusMatch[1])
        const allowed = this.allowedResponses.find(
            (candidate) =>
                (candidate.repeat || !candidate.consoleConsumed) &&
                candidate.page === page &&
                candidate.status === status &&
                candidate.pathname === pathname,
        )

        if (!allowed) {
            return false
        }

        if (!allowed.repeat) {
            allowed.consoleConsumed = true
        }

        return true
    }

    pruneAllowedResponses() {
        const now = Date.now()

        this.allowedResponses = this.allowedResponses.filter(
            (allowed) =>
                allowed.expiresAt > now &&
                !(
                    !allowed.repeat &&
                    allowed.responseConsumed &&
                    allowed.consoleConsumed
                ),
        )
    }

    bindAllowedLivewireRedirectRequest(request, page) {
        this.pruneAllowedLivewireRedirects()

        if (!this.isLivewireUpdateRequest(request)) {
            return
        }

        const sourcePathname = new URL(page.url()).pathname
        const allowed = this.allowedLivewireRedirects.find(
            (candidate) =>
                candidate.page === page &&
                candidate.request === null &&
                candidate.sourcePathname === sourcePathname,
        )

        if (allowed) {
            allowed.request = request
        }
    }

    deferAllowedLivewireRedirectFailure(request, page, label, message) {
        if (
            !message.includes('ERR_ABORTED') ||
            request.isNavigationRequest?.() === true
        ) {
            return false
        }

        this.pruneAllowedLivewireRedirects()
        const allowed = this.allowedLivewireRedirects.find(
            (candidate) =>
                candidate.page === page && candidate.request === request,
        )

        if (!allowed) {
            return false
        }

        if (allowed.navigationSucceeded) {
            this.allowedLivewireRedirects =
                this.allowedLivewireRedirects.filter(
                    (candidate) => candidate !== allowed,
                )

            return true
        }

        allowed.failure = {
            type: 'network',
            page: label,
            message,
            url: request.url(),
        }

        return true
    }

    consumeAllowedLivewireRedirect(response, page) {
        if (
            response.status() >= 400 ||
            response.request().isNavigationRequest?.() !== true ||
            response.request().frame?.() !== page.mainFrame?.()
        ) {
            return
        }

        this.pruneAllowedLivewireRedirects()
        const pathname = new URL(response.url()).pathname
        const allowed = this.allowedLivewireRedirects.find(
            (candidate) =>
                candidate.page === page &&
                this.pathnameMatches(candidate.destinationPathname, pathname),
        )

        if (!allowed) {
            return
        }

        allowed.navigationSucceeded = true

        if (allowed.failure !== null) {
            this.allowedLivewireRedirects =
                this.allowedLivewireRedirects.filter(
                    (candidate) => candidate !== allowed,
                )
        }
    }

    flushUnresolvedLivewireRedirectFailures() {
        for (const allowed of this.allowedLivewireRedirects) {
            if (allowed.failure === null || allowed.navigationSucceeded) {
                continue
            }

            this.recordFailure(
                allowed.failure.type,
                allowed.failure.page,
                allowed.failure.message,
                allowed.failure.url,
            )
        }

        this.allowedLivewireRedirects = this.allowedLivewireRedirects.filter(
            (allowed) =>
                allowed.failure === null && allowed.expiresAt > Date.now(),
        )
    }

    pruneAllowedLivewireRedirects() {
        const now = Date.now()
        const active = []

        for (const allowed of this.allowedLivewireRedirects) {
            if (allowed.expiresAt > now) {
                active.push(allowed)
                continue
            }

            if (allowed.failure !== null && !allowed.navigationSucceeded) {
                this.recordFailure(
                    allowed.failure.type,
                    allowed.failure.page,
                    allowed.failure.message,
                    allowed.failure.url,
                )
            }
        }

        this.allowedLivewireRedirects = active
    }

    isLivewireUpdateRequest(request) {
        return (
            request.method?.() === 'POST' &&
            /^\/livewire-[a-z0-9-]+\/update$/i.test(
                new URL(request.url()).pathname,
            )
        )
    }

    pathnameMatches(expected, actual) {
        return expected instanceof RegExp
            ? expected.test(actual)
            : expected === actual
    }

    recordFailure(type, page, message, url) {
        this.failures.push({
            type,
            page,
            message: redactText(message, this.secretValues),
            url: safeUrl(url),
            recordedAt: new Date().toISOString(),
        })
    }
}

module.exports = {
    JourneyDiagnostics,
    redactHtmlEvidence,
    redactText,
    redactValue,
    sanitisePageHtml,
}
