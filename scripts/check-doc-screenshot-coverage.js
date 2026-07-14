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

function localImagePaths(markdown) {
    return Array.from(markdown.matchAll(/!\[[^\]]*]\(([^)]+)\)/g))
        .map((match) => match[1])
        .filter((imagePath) => !/^https?:/.test(imagePath))
        .filter((imagePath) => !imagePath.includes('shields.io'))
}

function shouldRequireVisual(filePath) {
    const normalizedPath = filePath.replace(/^\.\//, '')

    if (ignoredMarkdownFiles.has(normalizedPath)) {
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

        for (const filePath of collectMarkdownFiles(repoRoot).sort()) {
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

        for (const entry of manifest.entries ?? []) {
            if (ids.has(entry.id)) {
                duplicateManifestIds.push(`${manifestPath} -> ${entry.id}`)
            }

            ids.add(entry.id)

            const outputPath = manifestOutputPath(manifest, entry)

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

    return { missingManifestOutputs, duplicateManifestIds }
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
