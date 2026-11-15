import fs from 'node:fs'
import { createRequire } from 'node:module'
import path from 'node:path'
import { pathToFileURL } from 'node:url'

function readManifest(applicationRoot) {
    const manifestPath = path.join(
        applicationRoot,
        'bootstrap/cache/capell-vite-inputs.json',
    )

    if (!fs.existsSync(manifestPath)) {
        return {}
    }

    return JSON.parse(fs.readFileSync(manifestPath, 'utf8'))
}

function stringList(value) {
    return Array.isArray(value)
        ? value.filter((item) => typeof item === 'string')
        : []
}

export function capellViteInputs(applicationRoot = process.cwd()) {
    return stringList(readManifest(applicationRoot).inputs)
}

// Resolve from the application, not from this file: vendor packages are often symlinks into another
// checkout, whose node_modules does not hold the application's plugins.
function resolvePluginModule(applicationRoot, name) {
    const requireFromApplication = createRequire(
        path.join(applicationRoot, 'package.json'),
    )

    try {
        return requireFromApplication.resolve(name)
    } catch (error) {
        if (error?.code !== 'ERR_PACKAGE_PATH_NOT_EXPORTED') {
            throw error
        }
    }

    // An ESM-only package exports "." only under the "import" condition, which require cannot see,
    // and may not export its package.json either, so find it through Node's own lookup directories.
    const packageJsonPath = (requireFromApplication.resolve.paths(name) ?? [])
        .map((directory) => path.join(directory, name, 'package.json'))
        .find((candidate) => fs.existsSync(candidate))

    if (packageJsonPath === undefined) {
        throw new Error(
            `Capell could not find the Vite plugin ${name}. Run your package manager's install, then build again.`,
        )
    }

    const exported = JSON.parse(fs.readFileSync(packageJsonPath, 'utf8'))
        .exports?.['.']
    const entry =
        typeof exported === 'string'
            ? exported
            : (exported?.import ?? exported?.default)

    if (typeof entry !== 'string') {
        throw new Error(
            `Capell could not find the ESM entry point of the Vite plugin ${name}.`,
        )
    }

    return path.join(path.dirname(packageJsonPath), entry)
}

export function capellVitePlugins(applicationRoot = process.cwd()) {
    const modules = stringList(readManifest(applicationRoot).plugins)

    return Promise.all(
        modules.map(async (name) => {
            const imported = await import(
                pathToFileURL(resolvePluginModule(applicationRoot, name)).href
            )

            if (typeof imported.default !== 'function') {
                throw new Error(
                    `The Vite plugin ${name} registered by a Capell package has no default export to call. Run your package manager's install, then build again.`,
                )
            }

            return imported.default()
        }),
    )
}
