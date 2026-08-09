const assert = require('node:assert/strict')
const { spawnSync } = require('node:child_process')
const fs = require('node:fs')
const os = require('node:os')
const path = require('node:path')
const test = require('node:test')

const {
    localImagePaths,
    printReport,
    runAudit,
    stripMarkdownCode,
} = require('./check-doc-screenshot-coverage')

const checkerPath = path.join(__dirname, 'check-doc-screenshot-coverage.js')

function createRepository(files) {
    const repoRoot = fs.mkdtempSync(
        path.join(os.tmpdir(), 'capell-doc-screenshot-check-'),
    )

    for (const [relativePath, contents] of Object.entries(files)) {
        const filePath = path.join(repoRoot, relativePath)

        fs.mkdirSync(path.dirname(filePath), { recursive: true })
        fs.writeFileSync(filePath, contents)
    }

    const initResult = spawnSync('git', ['init', '--quiet', repoRoot], {
        encoding: 'utf8',
    })
    assert.equal(initResult.status, 0, initResult.stderr)

    const addResult = spawnSync('git', ['-C', repoRoot, 'add', '.'], {
        encoding: 'utf8',
    })
    assert.equal(addResult.status, 0, addResult.stderr)

    return repoRoot
}

function removeRepository(repoRoot) {
    fs.rmSync(repoRoot, { recursive: true, force: true })
}

test('strips fenced and inline code before parsing Markdown and HTML images', () => {
    const markdown = [
        '![Real](docs/images/real.png)',
        '`![Inline](docs/images/inline.png)`',
        '```markdown',
        '![Fenced](docs/images/fenced.png)',
        '<picture><img src="docs/images/example.png"></picture>',
        '```',
        '<picture><source srcset="docs/images/real-dark.png 1x"><img src="docs/images/real.png"></picture>',
    ].join('\n')

    assert.deepEqual(localImagePaths(markdown), [
        'docs/images/real.png',
        'docs/images/real-dark.png',
        'docs/images/real.png',
    ])
    assert.doesNotMatch(stripMarkdownCode(markdown), /Inline|Fenced|example/)
})

test('reports deterministic relationship warnings without creating failures', (t) => {
    const duplicateContents = Buffer.from('same image')
    const manifest = JSON.stringify({
        entries: [
            {
                id: 'manifest-used',
                output: 'docs/images/manifest-used.png',
                required: true,
            },
            {
                id: 'manifest-unused',
                output: 'docs/images/manifest-unused.png',
                required: true,
            },
        ],
    })
    const repoRoot = createRepository({
        'docs/guide.md': [
            '[![Linked](images/linked.png)](images/linked.png)',
            '![Unlinked](images/unlinked.png)',
            '[Duplicate A](images/duplicate-a.png)',
            '[Duplicate B](images/duplicate-b.png)',
            '[Dark only](images/dark-only-dark.png)',
            '[Light only](images/light-only-light.png)',
            '[![Manifest](images/manifest-used.png)](images/manifest-used.png)',
            '<picture>',
            '  <source media="(prefers-color-scheme: dark)" srcset="images/theme-dark.png">',
            '  <img src="images/theme.png" alt="Theme">',
            '</picture>',
            '`![Ignored](images/missing-inline.png)`',
            '```markdown',
            '![Ignored](images/missing-fenced.png)',
            '```',
        ].join('\n'),
        'docs/images/dark-only-dark.png': 'dark only',
        'docs/images/duplicate-a.png': duplicateContents,
        'docs/images/duplicate-b.png': duplicateContents,
        'docs/images/light-only-light.png': 'light only',
        'docs/images/linked.png': 'linked',
        'docs/images/manifest-unused.png': 'manifest unused',
        'docs/images/manifest-used.png': 'manifest used',
        'docs/images/orphan.png': 'orphan',
        'docs/images/theme-dark.png': 'theme dark',
        'docs/images/theme.png': 'theme light',
        'docs/images/unlinked.png': 'unlinked',
        'docs/screenshots.json': manifest,
    })
    t.after(() => removeRepository(repoRoot))

    const result = runAudit({
        cwd: repoRoot,
        repoRoots: [repoRoot],
        screenshotManifests: [path.join(repoRoot, 'docs/screenshots.json')],
    })

    assert.deepEqual(result.failures, [])
    assert.deepEqual(result.rasterResult.unreferencedRasterAssets, [
        'docs/images/orphan.png',
    ])
    assert.deepEqual(result.rasterResult.missingLightDarkSiblings, [
        'docs/images/dark-only-dark.png -> missing docs/images/dark-only.png',
        'docs/images/light-only-light.png -> missing docs/images/light-only-dark.png',
    ])
    assert.equal(result.rasterResult.duplicateImageHashes.length, 1)
    assert.match(
        result.rasterResult.duplicateImageHashes[0],
        /^[a-f\d]{64} -> docs\/images\/duplicate-a\.png, docs\/images\/duplicate-b\.png$/,
    )
    assert.deepEqual(
        result.markdownResult.thumbnailsWithoutFullResolutionLinks,
        ['docs/guide.md -> images/unlinked.png'],
    )
    assert.deepEqual(result.rasterResult.unusedManifestOutputs, [
        'docs/screenshots.json -> manifest-unused -> docs/images/manifest-unused.png',
    ])
})

test('preserves required visuals, broken refs, manifest output, and duplicate ID failures', (t) => {
    const manifest = JSON.stringify({
        entries: [
            {
                id: 'duplicate',
                output: 'docs/images/missing-required.png',
                required: true,
            },
            { id: 'duplicate', required: false },
        ],
    })
    const repoRoot = createRepository({
        'README.md': '`![Code example](docs/images/example.png)`',
        'PACKAGE.md': '![Broken](docs/images/missing.png)',
        'docs/screenshots.json': manifest,
    })
    t.after(() => removeRepository(repoRoot))

    const result = runAudit({
        cwd: repoRoot,
        repoRoots: [repoRoot],
        screenshotManifests: [path.join(repoRoot, 'docs/screenshots.json')],
    })

    assert.deepEqual(result.markdownResult.missingVisuals, ['README.md'])
    assert.deepEqual(result.markdownResult.brokenVisuals, [
        'PACKAGE.md -> docs/images/missing.png',
    ])
    assert.deepEqual(result.manifestResult.missingManifestOutputs, [
        'docs/screenshots.json -> duplicate -> docs/images/missing-required.png',
    ])
    assert.deepEqual(result.manifestResult.duplicateManifestIds, [
        'docs/screenshots.json -> duplicate',
    ])
    assert.equal(result.failures.length, 4)
})

test('prints warnings without turning them into a failing report', () => {
    const calls = { error: [], log: [], warn: [] }
    const output = {
        error: (message) => calls.error.push(message),
        log: (message) => calls.log.push(message),
        warn: (message) => calls.warn.push(message),
    }

    printReport(
        {
            failures: [],
            warnings: [['Unused assets', ['docs/images/orphan.png']]],
        },
        output,
    )

    assert.deepEqual(calls.error, [])
    assert.deepEqual(calls.warn, [
        '\nWarning: Unused assets:',
        '- docs/images/orphan.png',
    ])
    assert.deepEqual(calls.log, [
        'Documentation screenshot coverage passed with warnings.',
    ])
})

test('CLI exits zero for warnings and nonzero only for legacy failures', (t) => {
    const warningRepo = createRepository({
        'README.md': '[![Hero](docs/images/hero.png)](docs/images/hero.png)',
        'docs/images/hero.png': 'hero',
        'docs/images/orphan.png': 'orphan',
    })
    const failureRepo = createRepository({
        'README.md': '![Broken](docs/images/missing.png)',
    })
    t.after(() => {
        removeRepository(warningRepo)
        removeRepository(failureRepo)
    })

    const warningResult = spawnSync(process.execPath, [checkerPath], {
        cwd: warningRepo,
        encoding: 'utf8',
    })
    const failureResult = spawnSync(process.execPath, [checkerPath], {
        cwd: failureRepo,
        encoding: 'utf8',
    })

    assert.equal(warningResult.status, 0, warningResult.stderr)
    assert.match(
        warningResult.stderr,
        /Warning: Tracked documentation raster assets/,
    )
    assert.equal(failureResult.status, 1)
    assert.match(failureResult.stderr, /Broken local visual references/)
})
