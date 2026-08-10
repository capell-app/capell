const { createHash } = require('node:crypto')
const { spawnSync } = require('node:child_process')
const fs = require('node:fs')
const path = require('node:path')

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

const rasterExtensions = new Set([
    '.avif',
    '.gif',
    '.jpeg',
    '.jpg',
    '.png',
    '.webp',
])

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

function repositoryConfiguration({
    cwd = process.cwd(),
    packagesRepo = process.env.CAPELL_PACKAGES_REPO,
} = {}) {
    const candidateRoots = [
        cwd,
        packagesRepo ?? path.resolve(cwd, '..', 'capell-packages-4'),
    ]
    const repoRoots = candidateRoots.filter(
        (repoRoot, index, roots) =>
            fs.existsSync(repoRoot) && roots.indexOf(repoRoot) === index,
    )
    const screenshotManifests = [
        ...coreScreenshotManifests
            .map((manifestPath) => path.resolve(cwd, manifestPath))
            .filter((manifestPath) => fs.existsSync(manifestPath)),
        ...repoRoots
            .filter((repoRoot) => repoRoot !== cwd)
            .flatMap((repoRoot) => collectScreenshotManifests(repoRoot)),
    ]

    return {
        cwd,
        repoRoots,
        screenshotManifests: [...new Set(screenshotManifests)].sort(),
    }
}

/**
 * This check guards documentation tracked by git. A null result lets callers
 * fall back to a filesystem walk when the checker is used outside a checkout.
 *
 * @return {string[]|null} Absolute paths, or null when git cannot answer.
 */
function collectTrackedFiles(repoRoot) {
    const result = spawnSync(
        'git',
        ['-C', repoRoot, 'ls-files', '--cached', '--exclude-standard', '-z'],
        { encoding: 'utf8' },
    )

    if (result.error || result.status !== 0) {
        return null
    }

    return result.stdout
        .split('\0')
        .filter(Boolean)
        .map((relativePath) => path.join(repoRoot, relativePath))
        .filter((filePath) => fs.existsSync(filePath))
        .filter(
            (filePath) =>
                !filePath
                    .split(path.sep)
                    .some((segment) => ignoredDirectories.has(segment)),
        )
}

function collectFiles(directory, predicate, files = []) {
    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
        if (ignoredDirectories.has(entry.name)) {
            continue
        }

        const entryPath = path.join(directory, entry.name)

        if (entry.isDirectory()) {
            collectFiles(entryPath, predicate, files)

            continue
        }

        if (predicate(entryPath)) {
            files.push(entryPath)
        }
    }

    return files
}

function collectMarkdownFiles(repoRoot) {
    const trackedFiles = collectTrackedFiles(repoRoot)

    if (trackedFiles !== null) {
        return trackedFiles.filter((filePath) => filePath.endsWith('.md'))
    }

    return collectFiles(repoRoot, (filePath) => filePath.endsWith('.md'))
}

function isRasterPath(filePath) {
    return rasterExtensions.has(path.extname(filePath).toLowerCase())
}

function isDocsPath(repoRelativePath) {
    const normalizedPath = repoRelativePath.split(path.sep).join('/')

    return (
        normalizedPath.startsWith('docs/') || normalizedPath.includes('/docs/')
    )
}

function collectTrackedDocsRasterFiles(repoRoot) {
    const trackedFiles = collectTrackedFiles(repoRoot)
    const candidates =
        trackedFiles ??
        collectFiles(repoRoot, (filePath) => isRasterPath(filePath))

    return candidates
        .filter((filePath) => isRasterPath(filePath))
        .filter((filePath) => isDocsPath(path.relative(repoRoot, filePath)))
        .sort()
}

function stripMarkdownCode(markdown) {
    const withoutFences = markdown.replace(
        /(^|\n)[ \t]*(`{3,}|~{3,})[^\n]*\n[\s\S]*?\n[ \t]*\2[ \t]*(?=\n|$)/g,
        '$1',
    )

    return withoutFences.replace(/`+[^`\n]*`+/g, '')
}

function markdownDestination(rawDestination) {
    const destination = rawDestination.trim()

    if (destination.startsWith('<')) {
        const closingBracket = destination.indexOf('>')

        if (closingBracket !== -1) {
            return destination.slice(1, closingBracket)
        }
    }

    return destination.split(/\s+/)[0]
}

function markdownImageReferences(proseOnly) {
    const linkedImageStarts = new Map()
    const linkedImagePattern =
        /\[(!\[[^\]]*\]\(\s*(?:<[^>]+>|[^)\s]+)(?:\s+[^)]*)?\))\]\(\s*(<[^>]+>|[^)\s]+)(?:\s+[^)]*)?\)/g

    for (const match of proseOnly.matchAll(linkedImagePattern)) {
        linkedImageStarts.set(match.index + 1, markdownDestination(match[2]))
    }

    const imagePattern = /!\[[^\]]*\]\(\s*(<[^>]+>|[^)\s]+)(?:\s+[^)]*)?\)/g

    return Array.from(proseOnly.matchAll(imagePattern), (match) => ({
        imagePath: markdownDestination(match[1]),
        fullResolutionPath: linkedImageStarts.get(match.index) ?? null,
    }))
}

function htmlAttributePaths(proseOnly, tagNames, attributeNames) {
    const tagPattern = new RegExp(`<(${tagNames})\\b[^>]*>`, 'gi')
    const attributePattern = new RegExp(
        `\\b(?:${attributeNames})\\s*=\\s*(["'])(.*?)\\1`,
        'gi',
    )
    const paths = []

    for (const tagMatch of proseOnly.matchAll(tagPattern)) {
        for (const attributeMatch of tagMatch[0].matchAll(attributePattern)) {
            paths.push(
                ...attributeMatch[2]
                    .split(',')
                    .map((candidate) => candidate.trim().split(/\s+/)[0])
                    .filter(Boolean),
            )
        }
    }

    return paths
}

function markdownLinkPaths(proseOnly) {
    const paths = []
    const linkedImagePattern =
        /\[!\[[^\]]*\]\(\s*(?:<[^>]+>|[^)\s]+)(?:\s+[^)]*)?\)\]\(\s*(<[^>]+>|[^)\s]+)(?:\s+[^)]*)?\)/g
    const markdownLinkPattern =
        /(?<!!)\[[^\]]*\]\(\s*(<[^>]+>|[^)\s]+)(?:\s+[^)]*)?\)/g

    for (const match of proseOnly.matchAll(linkedImagePattern)) {
        paths.push(markdownDestination(match[1]))
    }

    for (const match of proseOnly.matchAll(markdownLinkPattern)) {
        if (match[0].startsWith('[![')) {
            continue
        }

        paths.push(markdownDestination(match[1]))
    }

    return paths
}

function isLocalPath(referencePath) {
    return (
        referencePath !== '' &&
        !referencePath.startsWith('#') &&
        !/^[a-z][a-z\d+.-]*:/i.test(referencePath) &&
        !referencePath.startsWith('//')
    )
}

function localReferencePath(filePath, referencePath) {
    const pathWithoutQueryOrFragment = referencePath.split(/[?#]/)[0]
    let decodedPath = pathWithoutQueryOrFragment

    try {
        decodedPath = decodeURIComponent(pathWithoutQueryOrFragment)
    } catch {
        // Keep malformed escapes intact so a broken local reference is reported.
    }

    return path.resolve(path.dirname(filePath), decodedPath)
}

function markdownReferences(markdown) {
    const proseOnly = stripMarkdownCode(markdown)
    const markdownImages = markdownImageReferences(proseOnly)
    const htmlImages = htmlAttributePaths(proseOnly, 'img|source', 'src|srcset')
    const links = [
        ...markdownLinkPaths(proseOnly),
        ...htmlAttributePaths(proseOnly, 'a', 'href'),
    ]

    return {
        markdownImages,
        imagePaths: [
            ...markdownImages.map(({ imagePath }) => imagePath),
            ...htmlImages,
        ].filter(isLocalPath),
        linkPaths: links.filter(isLocalPath),
    }
}

// Kept as a narrow compatibility helper for callers interested in embeds only.
function localImagePaths(markdown) {
    return markdownReferences(markdown).imagePaths
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

function displayPath(cwd, repoRoot, filePath) {
    return path
        .join(
            path.relative(cwd, repoRoot) || '.',
            path.relative(repoRoot, filePath),
        )
        .split(path.sep)
        .join('/')
        .replace(/^\.\//, '')
}

function checkMarkdownVisuals({ cwd, repoRoots }) {
    const missingVisuals = []
    const brokenVisuals = []
    const thumbnailsWithoutFullResolutionLinks = []
    const referencedRasterAssets = new Set()

    for (const repoRoot of repoRoots) {
        for (const filePath of collectMarkdownFiles(repoRoot).sort()) {
            if (isExcludedFromAudit(path.relative(repoRoot, filePath))) {
                continue
            }

            const markdown = fs.readFileSync(filePath, 'utf8')
            const references = markdownReferences(markdown)
            const markdownDisplayPath = displayPath(cwd, repoRoot, filePath)

            if (
                repoRoot === cwd &&
                references.imagePaths.length === 0 &&
                shouldRequireVisual(markdownDisplayPath)
            ) {
                missingVisuals.push(markdownDisplayPath)
            }

            for (const imagePath of references.imagePaths) {
                const targetPath = localReferencePath(filePath, imagePath)

                if (isRasterPath(targetPath)) {
                    referencedRasterAssets.add(targetPath)
                }

                if (!fs.existsSync(targetPath)) {
                    brokenVisuals.push(`${markdownDisplayPath} -> ${imagePath}`)
                }
            }

            for (const linkPath of references.linkPaths) {
                const targetPath = localReferencePath(filePath, linkPath)

                if (isRasterPath(targetPath)) {
                    referencedRasterAssets.add(targetPath)
                }
            }

            for (const {
                imagePath,
                fullResolutionPath,
            } of references.markdownImages) {
                if (!isLocalPath(imagePath)) {
                    continue
                }

                const imageTargetPath = localReferencePath(filePath, imagePath)

                if (!isRasterPath(imageTargetPath)) {
                    continue
                }

                const fullResolutionTargetPath = fullResolutionPath
                    ? localReferencePath(filePath, fullResolutionPath)
                    : null
                const hasLocalFullResolutionLink =
                    fullResolutionPath !== null &&
                    isLocalPath(fullResolutionPath) &&
                    fullResolutionTargetPath !== null &&
                    isRasterPath(fullResolutionTargetPath) &&
                    fs.existsSync(fullResolutionTargetPath)

                if (!hasLocalFullResolutionLink) {
                    thumbnailsWithoutFullResolutionLinks.push(
                        `${markdownDisplayPath} -> ${imagePath}`,
                    )
                }
            }
        }
    }

    return {
        missingVisuals: missingVisuals.sort(),
        brokenVisuals: brokenVisuals.sort(),
        thumbnailsWithoutFullResolutionLinks:
            thumbnailsWithoutFullResolutionLinks.sort(),
        referencedRasterAssets,
    }
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

function owningRepoRoot(filePath, repoRoots, fallbackRoot) {
    return (
        repoRoots
            .filter((repoRoot) => {
                const relativePath = path.relative(repoRoot, filePath)

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
            .at(0) ?? fallbackRoot
    )
}

function checkScreenshotManifests({ cwd, repoRoots, screenshotManifests }) {
    const missingManifestOutputs = []
    const duplicateManifestIds = []
    const invalidProvenance = []
    const manifestOutputs = []

    for (const manifestPath of screenshotManifests.sort()) {
        const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'))
        const manifestRepoRoot = owningRepoRoot(manifestPath, repoRoots, cwd)
        const manifestDisplayPath = displayPath(
            cwd,
            manifestRepoRoot,
            manifestPath,
        )
        const ids = new Set()

        if (
            manifest.generatedFor !== 'shared-capell-screenshot-runner' ||
            manifest.provenancePolicy !== 'runner-only-v1'
        ) {
            invalidProvenance.push(
                `${manifestPath} -> manifest must declare shared-capell-screenshot-runner / runner-only-v1`,
            )
        }

        for (const entry of manifest.entries ?? []) {
            if (ids.has(entry.id)) {
                duplicateManifestIds.push(
                    `${manifestDisplayPath} -> ${entry.id}`,
                )
            }

            ids.add(entry.id)

            const outputPath = manifestOutputPath(manifest, entry)

            if (
                typeof entry.source === 'string' &&
                /capell\.app|marketing/i.test(entry.source)
            ) {
                invalidProvenance.push(
                    `${manifestPath} -> ${entry.id} -> direct marketing-App source is forbidden`,
                )
            }

            if (entry.acceptedEvidence === true) {
                if (manifest.provenancePolicy !== 'runner-only-v2') {
                    invalidProvenance.push(
                        `${manifestPath} -> ${entry.id} -> accepted evidence requires runner-only-v2`,
                    )
                }

                if (entry.scenario === 'static-html') {
                    invalidProvenance.push(
                        `${manifestPath} -> ${entry.id} -> static HTML cannot be accepted product evidence`,
                    )
                }

                if (
                    typeof entry.receiptPath !== 'string' ||
                    entry.receiptPath === ''
                ) {
                    invalidProvenance.push(
                        `${manifestPath} -> ${entry.id} -> accepted evidence requires a durable receiptPath`,
                    )
                }
            }

            if (!outputPath) {
                continue
            }

            const absoluteOutputPath = path.resolve(
                manifestRepoRoot,
                outputPath,
            )
            const outputExists = fs.existsSync(absoluteOutputPath)

            manifestOutputs.push({
                absolutePath: absoluteOutputPath,
                displayPath: `${manifestDisplayPath} -> ${entry.id} -> ${outputPath}`,
                exists: outputExists,
            })

            if (entry.required === true && !outputExists) {
                missingManifestOutputs.push(
                    `${manifestDisplayPath} -> ${entry.id} -> ${outputPath}`,
                )
            }
        }
    }

    return {
        missingManifestOutputs: missingManifestOutputs.sort(),
        duplicateManifestIds: duplicateManifestIds.sort(),
        manifestOutputs: manifestOutputs.sort((first, second) => {
            if (first.displayPath < second.displayPath) {
                return -1
            }

            return first.displayPath > second.displayPath ? 1 : 0
        }),
        invalidProvenance: invalidProvenance.sort(),
    }
}

function missingThemeSiblings(cwd, repoRoot, rasterFiles) {
    const rasterFileSet = new Set(rasterFiles)
    const warnings = []

    for (const filePath of rasterFiles) {
        const extension = path.extname(filePath)
        const pathWithoutExtension = filePath.slice(0, -extension.length)
        let siblingPath = null

        if (pathWithoutExtension.endsWith('-dark')) {
            siblingPath = `${pathWithoutExtension.slice(0, -'-dark'.length)}${extension}`
        } else if (pathWithoutExtension.endsWith('-light')) {
            siblingPath = `${pathWithoutExtension.slice(0, -'-light'.length)}-dark${extension}`
        }

        if (siblingPath && !rasterFileSet.has(siblingPath)) {
            warnings.push(
                `${displayPath(cwd, repoRoot, filePath)} -> missing ${displayPath(cwd, repoRoot, siblingPath)}`,
            )
        }
    }

    return warnings.sort()
}

function duplicateRasterHashes(cwd, rasterFilesByRepo) {
    const filesByHash = new Map()

    for (const { repoRoot, filePath } of rasterFilesByRepo) {
        const hash = createHash('sha256')
            .update(fs.readFileSync(filePath))
            .digest('hex')
        const paths = filesByHash.get(hash) ?? []

        paths.push(displayPath(cwd, repoRoot, filePath))
        filesByHash.set(hash, paths)
    }

    return Array.from(filesByHash.entries())
        .filter(([, paths]) => paths.length > 1)
        .map(([hash, paths]) => `${hash} -> ${paths.sort().join(', ')}`)
        .sort()
}

function checkRasterRelationships({
    cwd,
    repoRoots,
    markdownResult,
    manifestResult,
}) {
    const manifestOutputPaths = new Set(
        manifestResult.manifestOutputs.map(({ absolutePath }) => absolutePath),
    )
    const rasterFilesByRepo = repoRoots.flatMap((repoRoot) =>
        collectTrackedDocsRasterFiles(repoRoot).map((filePath) => ({
            repoRoot,
            filePath,
        })),
    )
    const unreferencedRasterAssets = rasterFilesByRepo
        .filter(
            ({ filePath }) =>
                !markdownResult.referencedRasterAssets.has(filePath) &&
                !manifestOutputPaths.has(filePath),
        )
        .map(({ repoRoot, filePath }) => displayPath(cwd, repoRoot, filePath))
        .sort()
    const missingLightDarkSiblings = repoRoots
        .flatMap((repoRoot) =>
            missingThemeSiblings(
                cwd,
                repoRoot,
                rasterFilesByRepo
                    .filter((entry) => entry.repoRoot === repoRoot)
                    .map(({ filePath }) => filePath),
            ),
        )
        .sort()
    const duplicateImageHashes = duplicateRasterHashes(cwd, rasterFilesByRepo)
    const unusedManifestOutputs = manifestResult.manifestOutputs
        .filter(
            ({ absolutePath, exists }) =>
                exists &&
                !markdownResult.referencedRasterAssets.has(absolutePath),
        )
        .map(({ displayPath: outputDisplayPath }) => outputDisplayPath)
        .sort()

    return {
        unreferencedRasterAssets,
        missingLightDarkSiblings,
        duplicateImageHashes,
        unusedManifestOutputs,
    }
}

function runAudit(options = {}) {
    const configuration = options.repoRoots
        ? {
              cwd: options.cwd ?? process.cwd(),
              repoRoots: options.repoRoots,
              screenshotManifests: options.screenshotManifests ?? [],
          }
        : repositoryConfiguration(options)
    const markdownResult = checkMarkdownVisuals(configuration)
    const manifestResult = checkScreenshotManifests(configuration)
    const rasterResult = checkRasterRelationships({
        ...configuration,
        markdownResult,
        manifestResult,
    })

    const failures = [
        ['Markdown files without local visuals', markdownResult.missingVisuals],
        ['Broken local visual references', markdownResult.brokenVisuals],
        [
            'Required manifest outputs missing',
            manifestResult.missingManifestOutputs,
        ],
        ['Duplicate manifest IDs', manifestResult.duplicateManifestIds],
        [
            'Invalid screenshot provenance declarations',
            manifestResult.invalidProvenance,
        ],
    ].filter(([, entries]) => entries.length > 0)
    const warnings = [
        [
            'Tracked documentation raster assets not referenced by Markdown or a manifest',
            rasterResult.unreferencedRasterAssets,
        ],
        [
            'Missing light/dark screenshot siblings',
            rasterResult.missingLightDarkSiblings,
        ],
        [
            'Duplicate documentation raster hashes',
            rasterResult.duplicateImageHashes,
        ],
        [
            'Markdown screenshot thumbnails without local full-resolution links',
            markdownResult.thumbnailsWithoutFullResolutionLinks,
        ],
        [
            'Existing manifest outputs not referenced by published Markdown',
            rasterResult.unusedManifestOutputs,
        ],
    ].filter(([, entries]) => entries.length > 0)

    return { failures, warnings, markdownResult, manifestResult, rasterResult }
}

function printReport({ failures, warnings }, output = console) {
    for (const [title, entries] of failures) {
        output.error(`\n${title}:`)
        for (const entry of entries) {
            output.error(`- ${entry}`)
        }
    }

    for (const [title, entries] of warnings) {
        output.warn(`\nWarning: ${title}:`)
        for (const entry of entries) {
            output.warn(`- ${entry}`)
        }
    }

    if (failures.length === 0 && warnings.length === 0) {
        output.log('Documentation screenshot coverage looks good.')
    } else if (failures.length === 0) {
        output.log('Documentation screenshot coverage passed with warnings.')
    }
}

function main() {
    const result = runAudit()

    printReport(result)

    if (result.failures.length > 0) {
        process.exitCode = 1
    }
}

if (require.main === module) {
    main()
}

module.exports = {
    checkMarkdownVisuals,
    checkRasterRelationships,
    checkScreenshotManifests,
    collectTrackedDocsRasterFiles,
    localImagePaths,
    manifestOutputPath,
    markdownReferences,
    printReport,
    repositoryConfiguration,
    runAudit,
    stripMarkdownCode,
}
