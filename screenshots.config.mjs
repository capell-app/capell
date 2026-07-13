import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const repositoryRoot = dirname(fileURLToPath(import.meta.url))
const appUrl = 'http://127.0.0.1:8145'

export default {
    schemaVersion: 1,
    repoRoots: ['.'],
    outputRoots: [
        'docs/images',
        'packages/admin/docs/images',
        'packages/core/docs/images',
        'packages/frontend/docs/images',
        'packages/installer/docs/images',
        'packages/marketplace/docs/images',
    ],
    app: {
        cwd: '.',
        serve: {
            command: 'php',
            args: [
                '-d',
                'memory_limit=-1',
                'vendor/bin/testbench',
                'serve',
                '--host=127.0.0.1',
                '--port=8145',
                '--no-reload',
                '--no-interaction',
            ],
            readyUrl: `${appUrl}/__ping`,
            environment: {
                PHPRC: join(repositoryRoot, 'workbench/php'),
            },
        },
    },
    urls: {
        frontend: appUrl,
        admin: `${appUrl}/admin`,
    },
    environment: {
        APP_URL: appUrl,
        APP_KEY: 'base64:/MjiNkPfjAngJBfuMDsnFBxDynZGOKk3O6P0u0MhvJE=',
        DB_CONNECTION: 'sqlite',
        DB_DATABASE: join(
            repositoryRoot,
            'workbench/database/screenshots.sqlite',
        ),
        CAPELL_SCREENSHOT_DATABASE: join(
            repositoryRoot,
            'workbench/database/screenshots.sqlite',
        ),
        CACHE_STORE: 'array',
        SESSION_DRIVER: 'file',
        QUEUE_CONNECTION: 'sync',
        DEBUGBAR_ENABLED: 'false',
        CAPELL_FRONTEND_PUBLIC_VIEW_QUERY_GUARD_ENABLED: 'false',
        CAPELL_MARKETPLACE_URL: `${appUrl}/api/v1`,
        CAPELL_MARKETPLACE_WEB_URL: appUrl,
    },
    authentication: {
        defaultUser: 'admin',
        profiles: {
            admin: {
                email: 'admin@example.com',
                password: 'password',
            },
        },
    },
    capture: {
        concurrency: 3,
        serverWorkers: 4,
    },
    report: {
        path: 'storage/framework/testing/core-screenshot-report.json',
    },
}
