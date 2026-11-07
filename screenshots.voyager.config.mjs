import { isAbsolute } from 'node:path'

function requiredEnvironment(name) {
    const value = process.env[name]
    if (typeof value !== 'string' || value.trim() === '') {
        throw new Error(`${name} is required for the Voyager capture profile.`)
    }
    return value
}

const captureUrl = new URL(requiredEnvironment('CAPELL_VOYAGER_CAPTURE_ORIGIN'))
if (
    !['http:', 'https:'].includes(captureUrl.protocol) ||
    !['127.0.0.1', 'localhost', '[::1]'].includes(captureUrl.hostname) ||
    captureUrl.username ||
    captureUrl.password ||
    captureUrl.pathname !== '/' ||
    captureUrl.search ||
    captureUrl.hash
) {
    throw new Error(
        'CAPELL_VOYAGER_CAPTURE_ORIGIN must be a local origin without credentials or a path.',
    )
}
const origin = captureUrl.origin
const control = requiredEnvironment('CAPELL_VOYAGER_FIXTURE_CONTROL')
if (!isAbsolute(control))
    throw new Error(
        'CAPELL_VOYAGER_FIXTURE_CONTROL must be an absolute executable path.',
    )

export default {
    schemaVersion: 1,
    repoRoots: ['.'],
    outputRoots: ['docs/images'],
    preCapture: 'scripts/screenshots/verify-voyager-runtime.mjs',
    scenarios: {
        'voyager-guide-admin': 'scripts/screenshots/capture-voyager-guide.mjs',
    },
    app: {
        cwd: '.',
        serve: {
            command: control,
            args: ['serve'],
            readyUrl: `${origin}/fpm-ping`,
            startupTimeoutMs: 90_000,
            shutdownTimeoutMs: 15_000,
            environment: { CAPELL_SCREENSHOT_DISPLAY_ORIGIN: '' },
        },
    },
    urls: { frontend: origin, admin: `${origin}/admin` },
    authentication: {
        defaultUser: 'voyager',
        profiles: {
            voyager: {
                adminUi: 'voyager',
                login: {
                    path: '/login',
                    formSelector: 'form[action$="/admin/login"]',
                    emailSelector: 'input[name="email"]',
                    passwordSelector: 'input[name="password"]',
                    submitSelector: 'button[type="submit"]',
                    authenticatedSelector: 'body.voyager .side-menu',
                },
            },
        },
    },
    capture: { concurrency: 1, serverWorkers: 1 },
    report: {
        path: 'docs/screenshot-receipts/voyager-migration/source-audit.json',
    },
}
