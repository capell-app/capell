const { spawnSync } = require('node:child_process')
const fs = require('fs')
const path = require('path')

const ignoredDirectories = new Set([
    '.claude',
    '.git',
    'node_modules',
    'storage',
    'vendor',
])

const ignoredMarkdownPrefixes = [
    '.github/',
    'docs/', // documentation pages may be text-only; referenced visuals are still validated
    'packages/core/resources/boost/skills/',
]

/**
 * Local planning notes are working material, not shipped documentation. They
 * quote proposed markup for other files, so their image paths are relative to
 * wherever that markup will land rather than to the note itself. They are
 * excluded from the audit entirely, not merely from the visual requirement.
 */
const excludedMarkdownPrefixes = ['.superpowers/', 'docs/superpowers/']

const ignoredMarkdownFiles = new Set([
    'ACTION-PLAN.md',
    'CHANGELOG.md',
    'CODE_OF_CONDUCT.md',
    'CONTEXT-MAP.md',
    'CONTRIBUTING.md',
    'FULL-AUDIT-REPORT.md',
    'LICENSE.md',
    'SECURITY.md',
])

const coreScreenshotManifests = [
    'docs/screenshots.json',
    'packages/admin/docs/screenshots.json',
    'packages/core/docs/screenshots.json',
    'packages/frontend/docs/screenshots.json',
    'packages/installer/docs/screenshots.json',
    'packages/marketplace/docs/screenshots.json',
]

const repoRoots = [
    process.cwd(),
    process.env.CAPELL_PACKAGES_REPO ??
        path.resolve(process.cwd(), '..', 'capell-packages-4'),
].filter(
    (repoRoot, index, roots) =>
        fs.existsSync(repoRoot) && roots.indexOf(repoRoot) === index,
)

function collectScreenshotManifests(directory, files = []) {
    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
        if (ignoredDirectories.has(entry.name)) {
            continue
        }

        const entryPath = path.join(directory, entry.name)

        if (entry.isDirectory()) {
            collectScreenshotManifests(entryPath, files)

            continue
        }

        if (
            entry.name === 'screenshots.json' &&
            path.basename(directory) === 'docs'
        ) {
            files.push(entryPath)
        }
    }

    return files
}

const screenshotManifests = [
    ...coreScreenshotManifests.map((manifestPath) =>
        path.resolve(process.cwd(), manifestPath),
    ),
    ...repoRoots
        .filter((repoRoot) => repoRoot !== process.cwd())
        .flatMap((repoRoot) => collectScreenshotManifests(repoRoot)),
]

/**
 * This check guards the documentation that ships with the repository. Untracked
 * and gitignored paths — local planning notes under docs/superpowers, scratch
 * output, generated bundles — are not part of that surface, and their Markdown
 * routinely quotes proposed markup whose paths are only valid somewhere else.
 * Enumerating through git keeps the audit to committed documentation.
 *
 * @return {string[]|null} Absolute paths, or null when git cannot answer.
 */
function collectTrackedMarkdownFiles(repoRoot) {
    const result = spawnSync(
        'git',
        [
            '-C',
            repoRoot,
            'ls-files',
            '--cached',
            '--exclude-standard',
            '-z',
            '*.md',
        ],
        { encoding: 'utf8' },
    )

    if (result.error || result.status !== 0) {
        return null
    }

    return result.stdout
        .split('\0')
        .filter(Boolean)
        .map((relativePath) => path.join(repoRoot, relativePath))
        .filter(
            (filePath) =>
                !filePath
                    .split(path.sep)
                    .some((segment) => ignoredDirectories.has(segment)),
        )
}

function collectMarkdownFiles(directory, files = []) {
    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
        if (ignoredDirectories.has(entry.name)) {
            continue
        }

        const entryPath = path.join(directory, entry.name)

        if (entry.isDirectory()) {
            collectMarkdownFiles(entryPath, files)

            continue
        }

        if (entryPath.endsWith('.md')) {
            files.push(entryPath)
        }
    }

    return files
}

// Theme-aware embeds use a <picture> block, so light and dark sources are HTML
// attributes rather than Markdown image syntax. Both forms are validated.
function localImagePaths(markdown) {
    const markdownPaths = Array.from(
        markdown.matchAll(/!\[[^\]]*]\(([^)]+)\)/g),
    ).map((match) => match[1])

    // Fenced and inline code carries example markup such as
    // <img src="@frontendAsset(...)">, which is documentation rather than a
    // reference to a file on disk.
    const proseOnly = markdown
        .replace(/```[\s\S]*?```/g, '')
        .replace(/`[^`\n]*`/g, '')

    const htmlPaths = Array.from(
        proseOnly.matchAll(
            /<(?:img|source)\b[^>]*?\b(?:src|srcset)="([^"]+)"/g,
        ),
    ).flatMap((match) =>
        // A srcset lists comma-separated candidates, each optionally followed by
        // a descriptor such as "2x" or "800w".
        match[1]
            .split(',')
            .map((candidate) => candidate.trim().split(/\s+/)[0])
            .filter(Boolean),
    )

    return [...markdownPaths, ...htmlPaths]
        .filter((imagePath) => !/^https?:/.test(imagePath))
        .filter((imagePath) => !imagePath.includes('shields.io'))
}

function isExcludedFromAudit(repoRelativePath) {
    const normalizedPath = repoRelativePath
        .split(path.sep)
        .join('/')
        .replace(/^\.\//, '')

    return excludedMarkdownPrefixes.some((prefix) =>
        normalizedPath.startsWith(prefix),
    )
}

function shouldRequireVisual(filePath) {
    const normalizedPath = filePath.replace(/^\.\//, '')
    const basename = path.basename(normalizedPath)

    if (
        ignoredMarkdownFiles.has(normalizedPath) ||
        ignoredMarkdownFiles.has(basename) ||
        basename === 'AGENTS.md' ||
        normalizedPath.includes('/docs/')
    ) {
        return false
    }

    return !ignoredMarkdownPrefixes.some((prefix) =>
        normalizedPath.startsWith(prefix),
    )
}

function checkMarkdownVisuals() {
    const missingVisuals = []
    const brokenVisuals = []

    for (const repoRoot of repoRoots) {
        const relativeRepoRoot = path.relative(process.cwd(), repoRoot) || '.'

        const markdownFiles =
            collectTrackedMarkdownFiles(repoRoot) ??
            collectMarkdownFiles(repoRoot)

        for (const filePath of markdownFiles.sort()) {
            if (isExcludedFromAudit(path.relative(repoRoot, filePath))) {
                continue
            }

            const markdown = fs.readFileSync(filePath, 'utf8')
            const imagePaths = localImagePaths(markdown)
            const displayPath = path
                .join(relativeRepoRoot, path.relative(repoRoot, filePath))
                .replace(/^\.\//, '')

            if (
                repoRoot === process.cwd() &&
                imagePaths.length === 0 &&
                shouldRequireVisual(displayPath)
            ) {
                missingVisuals.push(displayPath)
            }

            for (const imagePath of imagePaths) {
                const targetPath = path.resolve(
                    path.dirname(filePath),
                    imagePath.split('#')[0],
                )

                if (!fs.existsSync(targetPath)) {
                    brokenVisuals.push(`${displayPath} -> ${imagePath}`)
                }
            }
        }
    }

    return { missingVisuals, brokenVisuals }
}

function manifestOutputPath(manifest, entry) {
    if (entry.output) {
        return entry.output
    }

    if (entry.screenshotPath) {
        return entry.screenshotPath
    }

    if (manifest.outputDirectory && entry.id) {
        return `${manifest.outputDirectory}/${entry.id}.png`
    }

    return null
}

function checkScreenshotManifests() {
    const missingManifestOutputs = []
    const duplicateManifestIds = []
    const invalidProvenance = []

    for (const manifestPath of screenshotManifests) {
        const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'))
        const manifestRepoRoot =
            repoRoots
                .filter((repoRoot) => {
                    const relativePath = path.relative(repoRoot, manifestPath)

                    return (
                        relativePath === '' ||
                        (!relativePath.startsWith('..') &&
                            !path.isAbsolute(relativePath))
                    )
                })
                .sort(
                    (firstRepoRoot, secondRepoRoot) =>
                        secondRepoRoot.length - firstRepoRoot.length,
                )
                .at(0) ?? process.cwd()
        const ids = new Set()

        if (manifest.generatedFor !== 'shared-capell-screenshot-runner' || manifest.provenancePolicy !== 'runner-only-v1') {
            invalidProvenance.push(`${manifestPath} -> manifest must declare shared-capell-screenshot-runner / runner-only-v1`)
        }

        for (const entry of manifest.entries ?? []) {
            if (ids.has(entry.id)) {
                duplicateManifestIds.push(`${manifestPath} -> ${entry.id}`)
            }

            ids.add(entry.id)

            const outputPath = manifestOutputPath(manifest, entry)

            if (typeof entry.source === 'string' && /capell\.app|marketing/i.test(entry.source)) {
                invalidProvenance.push(`${manifestPath} -> ${entry.id} -> direct marketing-App source is forbidden`)
            }

            if (
                entry.required === true &&
                outputPath &&
                !fs.existsSync(path.resolve(manifestRepoRoot, outputPath))
            ) {
                missingManifestOutputs.push(
                    `${manifestPath} -> ${entry.id} -> ${outputPath}`,
                )
            }
        }
    }

    return { missingManifestOutputs, duplicateManifestIds, invalidProvenance }
}

const markdownResult = checkMarkdownVisuals()
const manifestResult = checkScreenshotManifests()

const failures = [
    ['Markdown files without local visuals', markdownResult.missingVisuals],
    ['Broken local visual references', markdownResult.brokenVisuals],
    [
        'Required manifest outputs missing',
        manifestResult.missingManifestOutputs,
    ],
    ['Duplicate manifest IDs', manifestResult.duplicateManifestIds],
    ['Invalid screenshot provenance declarations', manifestResult.invalidProvenance],
].filter(([, entries]) => entries.length > 0)

if (failures.length > 0) {
    for (const [title, entries] of failures) {
        console.error(`\n${title}:`)
        for (const entry of entries) {
            console.error(`- ${entry}`)
        }
    }

    process.exitCode = 1
} else {
    console.log('Documentation screenshot coverage looks good.')
}
