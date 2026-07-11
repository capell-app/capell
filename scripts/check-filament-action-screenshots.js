const fs = require('fs')
const path = require('path')

const coreRepoRoot = process.cwd()
const packagesRepoRoot = process.env.CAPELL_PACKAGES_REPO
const baselinePath = path.resolve(
    coreRepoRoot,
    'docs/development/filament-action-screenshot-baseline.json',
)

const ignoredDirectories = new Set([
    '.git',
    'database',
    'node_modules',
    'resources',
    'storage',
    'tests',
    'vendor',
])

const standardActionMakers = new Set([
    'CreateAction',
    'EditAction',
    'ViewAction',
    'DeleteAction',
    'RestoreAction',
    'ForceDeleteAction',
    'ReplicateAction',
    'DeleteBulkAction',
    'RestoreBulkAction',
    'ForceDeleteBulkAction',
])

const customBehaviourMethods = [
    'action',
    'after',
    'before',
    'extraModalFooterActions',
    'form',
    'modal',
    'modalActions',
    'modalCancelAction',
    'modalContent',
    'modalDescription',
    'modalFooterActions',
    'modalHeading',
    'modalSubmitAction',
    'mutateFormDataUsing',
    'requiresConfirmation',
    'schema',
    'steps',
    'url',
    'using',
    'wizard',
]

function repoRoots() {
    return [coreRepoRoot, packagesRepoRoot]
        .filter((repoRoot) => repoRoot && fs.existsSync(repoRoot))
        .filter((repoRoot, index, roots) => roots.indexOf(repoRoot) === index)
}

function repoName(repoRoot) {
    return path.resolve(repoRoot) === path.resolve(coreRepoRoot)
        ? 'current'
        : 'capell-packages-4'
}

function walk(directory, files = []) {
    if (!fs.existsSync(directory)) {
        return files
    }

    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
        if (ignoredDirectories.has(entry.name)) {
            continue
        }

        const entryPath = path.join(directory, entry.name)

        if (entry.isDirectory()) {
            walk(entryPath, files)

            continue
        }

        files.push(entryPath)
    }

    return files
}

function filamentPhpFiles(repoRoot) {
    return walk(path.join(repoRoot, 'packages'))
        .filter((filePath) => filePath.endsWith('.php'))
        .filter((filePath) => filePath.includes(`${path.sep}src${path.sep}`))
        .filter(
            (filePath) =>
                filePath.includes(`${path.sep}Filament${path.sep}`) ||
                filePath.includes(
                    `${path.sep}Livewire${path.sep}Filament${path.sep}`,
                ),
        )
}

function collectScreenshotManifests(repoRoot) {
    return walk(repoRoot).filter(
        (filePath) =>
            path.basename(filePath) === 'screenshots.json' &&
            path.basename(path.dirname(filePath)) === 'docs',
    )
}

function coveredSourceFiles() {
    const covered = new Set()

    for (const repoRoot of repoRoots()) {
        for (const manifestPath of collectScreenshotManifests(repoRoot)) {
            const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'))

            for (const entry of manifest.entries ?? []) {
                for (const sourceFile of entry.covers ?? []) {
                    covered.add(`${repoName(repoRoot)}:${sourceFile}`)
                }
            }
        }
    }

    return covered
}

function actionBlock(source, start) {
    const nextAction = source
        .slice(start + 1)
        .search(/(?<![A-Za-z0-9_\\])([A-Za-z_][A-Za-z0-9_\\]*)::make\s*\(/)
    const end =
        nextAction === -1
            ? Math.min(source.length, start + 2500)
            : Math.min(source.length, start + 1 + nextAction)

    return source.slice(start, end)
}

function shortClassName(className) {
    return className.split('\\').pop()
}

function methodPattern(methodName) {
    return new RegExp(`->${methodName}\\s*\\(`)
}

function customBehaviourFor(className, block) {
    const shortName = shortClassName(className)

    if (
        ['Action', 'BulkAction', 'StaticAction', 'FilamentAction'].includes(
            shortName,
        ) ||
        (!standardActionMakers.has(shortName) &&
            (shortName.endsWith('Action') || shortName.endsWith('BulkAction')))
    ) {
        return ['custom action maker']
    }

    if (!standardActionMakers.has(shortName)) {
        return []
    }

    const methods = customBehaviourMethods.filter((methodName) =>
        methodPattern(methodName).test(block),
    )

    return methods.length > 0
        ? methods.map((methodName) => `custom ${methodName}()`)
        : []
}

function customActionSurfaces() {
    const surfaces = []

    for (const repoRoot of repoRoots()) {
        for (const filePath of filamentPhpFiles(repoRoot)) {
            const source = fs.readFileSync(filePath, 'utf8')
            const actions = []
            const reasons = []

            for (const match of source.matchAll(
                /(?<![A-Za-z0-9_\\])([A-Za-z_][A-Za-z0-9_\\]*)::make\s*\(/g,
            )) {
                const className = match[1]
                const behaviour = customBehaviourFor(
                    className,
                    actionBlock(source, match.index),
                )

                if (behaviour.length === 0) {
                    continue
                }

                actions.push(shortClassName(className))
                reasons.push(...behaviour)
            }

            if (actions.length === 0) {
                continue
            }

            surfaces.push({
                repo: repoName(repoRoot),
                file: path.relative(repoRoot, filePath),
                actions: [...new Set(actions)].sort(),
                reasons: [...new Set(reasons)].sort(),
            })
        }
    }

    return surfaces.sort((firstSurface, secondSurface) =>
        `${firstSurface.repo}:${firstSurface.file}`.localeCompare(
            `${secondSurface.repo}:${secondSurface.file}`,
        ),
    )
}

function readBaseline() {
    if (!fs.existsSync(baselinePath)) {
        return { uncovered: [] }
    }

    return JSON.parse(fs.readFileSync(baselinePath, 'utf8'))
}

function key(surface) {
    return `${surface.repo}:${surface.file}`
}

function writeBaseline(uncovered) {
    fs.writeFileSync(
        baselinePath,
        `${JSON.stringify(
            {
                generatedAt: new Date().toISOString(),
                description:
                    'Current custom Filament action surfaces that still need explicit screenshot manifest coverage. Remove entries as manifests gain covers[] links.',
                uncovered,
            },
            null,
            2,
        )}\n`,
    )
}

function main() {
    const surfaces = customActionSurfaces()
    const covered = coveredSourceFiles()
    const uncovered = surfaces.filter((surface) => !covered.has(key(surface)))

    if (process.argv.includes('--write-baseline')) {
        writeBaseline(uncovered)
        console.log(
            `Wrote ${uncovered.length} uncovered custom action surfaces to ${path.relative(coreRepoRoot, baselinePath)}.`,
        )

        return 0
    }

    const baseline = readBaseline()
    const baselineKeys = new Set((baseline.uncovered ?? []).map(key))
    const uncoveredKeys = new Set(uncovered.map(key))
    const newUncovered = uncovered.filter(
        (surface) => !baselineKeys.has(key(surface)),
    )
    const staleBaseline = (baseline.uncovered ?? []).filter(
        (surface) => !uncoveredKeys.has(key(surface)),
    )

    if (newUncovered.length === 0 && staleBaseline.length === 0) {
        console.log(
            `Custom Filament action screenshot coverage baseline is current (${uncovered.length} known gaps).`,
        )

        return 0
    }

    if (newUncovered.length > 0) {
        console.error('\nNew custom Filament action surfaces need screenshots:')
        for (const surface of newUncovered) {
            console.error(
                `- ${key(surface)} (${surface.actions.join(', ')}; ${surface.reasons.join(', ')})`,
            )
        }
    }

    if (staleBaseline.length > 0) {
        console.error(
            '\nStale custom Filament action screenshot baseline entries:',
        )
        for (const surface of staleBaseline) {
            console.error(`- ${key(surface)}`)
        }
    }

    console.error(
        '\nAdd a screenshot manifest entry with covers: ["path/to/source.php"], or run `node scripts/check-filament-action-screenshots.js --write-baseline` only when intentionally accepting the current backlog.',
    )

    return 1
}

process.exitCode = main()
