const { defineConfig, devices } = require('@playwright/test')

module.exports = defineConfig({
    testDir: '.',
    testMatch: ['packages/**/tests/Browser/**/*.spec.js'],
    timeout: 60_000,
    expect: {
        timeout: 10_000,
    },
    projects: [
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
            },
        },
    ],
})
