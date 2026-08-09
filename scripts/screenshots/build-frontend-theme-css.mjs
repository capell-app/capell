#!/usr/bin/env node

// Compile the generated workbench theme input to a stable public path used only
// by the isolated screenshot fixture. The source remains generated install
// output; the browser capture remains evidence from the installed public route.

import {
    existsSync,
    mkdirSync,
    readFileSync,
    statSync,
    writeFileSync,
} from 'node:fs'
import { spawnSync } from 'node:child_process'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const repoRoot = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const testbenchAppRoot = join(
    repoRoot,
    'vendor',
    'orchestra',
    'testbench-core',
    'laravel',
)
const sourcePath = join(
    testbenchAppRoot,
    'resources',
    'css',
    'capell',
    'frontend.css',
)
const buildSourcePath = join(
    testbenchAppRoot,
    'resources',
    'css',
    'capell',
    'frontend.screenshot.css',
)
const outputPath = join(
    testbenchAppRoot,
    'public',
    'build',
    'screenshots',
    'default-theme.css',
)

function buildFrontendThemeCss() {
    if (!existsSync(sourcePath)) {
        console.error(
            `Generated frontend theme input is missing: ${sourcePath}. Run the screenshot workbench installer first.`,
        )

        return 1
    }

    mkdirSync(dirname(outputPath), { recursive: true })

    const installedPackageImport = join(
        testbenchAppRoot,
        'vendor',
        'capell-app',
        'frontend',
        'resources',
        'css',
        'capell-frontend.css',
    )
    const monorepoPackageImport = join(
        repoRoot,
        'packages',
        'frontend',
        'resources',
        'css',
        'capell-frontend.css',
    )
    let buildSource = readFileSync(sourcePath, 'utf8')

    if (!existsSync(installedPackageImport)) {
        if (!existsSync(monorepoPackageImport)) {
            console.error(
                `Frontend package theme source is missing: ${monorepoPackageImport}.`,
            )

            return 1
        }

        const generatedImport =
            '../../../vendor/capell-app/frontend/resources/css/capell-frontend.css'

        if (!buildSource.includes(generatedImport)) {
            console.error(
                `Generated frontend theme input does not contain the expected package import: ${generatedImport}.`,
            )

            return 1
        }

        buildSource = buildSource.replace(
            generatedImport,
            monorepoPackageImport,
        )
    }

    writeFileSync(buildSourcePath, buildSource)

    const cli = join(repoRoot, 'node_modules', '.bin', 'tailwindcss')
    const result = spawnSync(
        cli,
        ['--input', buildSourcePath, '--output', outputPath, '--minify'],
        {
            cwd: repoRoot,
            stdio: 'inherit',
        },
    )

    if (result.status !== 0) {
        console.error('Frontend screenshot theme build failed.')

        return result.status ?? 1
    }

    const kilobytes = Math.round(statSync(outputPath).size / 1024)
    console.log(`Built ${outputPath} (${kilobytes} KB).`)

    return 0
}

process.exitCode = buildFrontendThemeCss()
