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

    const entry = esmEntry(
        JSON.parse(fs.readFileSync(packageJsonPath, 'utf8')).exports?.['.'],
    )

    if (typeof entry !== 'string') {
        throw new Error(
            `Capell could not find the ESM entry point of the Vite plugin ${name}.`,
        )
    }

    return path.join(path.dirname(packageJsonPath), entry)
}

// Follows nested export conditions, such as { import: { types, default } }, to the ESM file.
function esmEntry(exported) {
    if (
        typeof exported === 'string' ||
        exported === undefined ||
        exported === null
    ) {
        return exported
    }

    return esmEntry(exported.import ?? exported.default)
}

function pluginEntries(manifest) {
    return (Array.isArray(manifest.plugins) ? manifest.plugins : [])
        .map((plugin) =>
            typeof plugin === 'string'
                ? { module: plugin, options: {} }
                : plugin,
        )
        .filter((plugin) => typeof plugin?.module === 'string')
}

// Path-repository installs symlink Capell packages into vendor/capell-app. Vite follows
// the symlink to the package's real directory, where a bare import such as
// '@inertiajs/vue3' finds no node_modules. Keeping the symlinked path lets those
// imports resolve from the application's own node_modules.
function pathRepositoryPackagesPlugin(applicationRoot) {
    const vendorDirectory = path.join(applicationRoot, 'vendor', 'capell-app')
    let symlinked = false

    try {
        symlinked = fs
            .readdirSync(vendorDirectory)
            .some((entry) =>
                fs
                    .lstatSync(path.join(vendorDirectory, entry))
                    .isSymbolicLink(),
            )
    } catch {
        return null
    }

    return symlinked
        ? {
              name: 'capell:path-repository-packages',
              config: () => ({ resolve: { preserveSymlinks: true } }),
          }
        : null
}

export function capellVitePlugins(applicationRoot = process.cwd()) {
    const pathRepositoryPlugin = pathRepositoryPackagesPlugin(applicationRoot)

    return Promise.all(
        pluginEntries(readManifest(applicationRoot)).map(
            async ({ module: name, options }) => {
                const imported = await import(
                    pathToFileURL(resolvePluginModule(applicationRoot, name))
                        .href
                )

                // A CommonJS build that sets exports.default arrives as { default: { default: factory } }.
                const factory =
                    typeof imported.default === 'function'
                        ? imported.default
                        : imported.default?.default

                if (typeof factory !== 'function') {
                    throw new Error(
                        `The Vite plugin ${name} registered by a Capell package has no default export to call. Run your package manager's install, then build again.`,
                    )
                }

                return factory(options ?? {})
            },
        ),
    ).then((plugins) =>
        pathRepositoryPlugin === null
            ? plugins
            : [pathRepositoryPlugin, ...plugins],
    )
}
