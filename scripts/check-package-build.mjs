import { execFileSync } from 'node:child_process'
import {
    cpSync,
    existsSync,
    mkdirSync,
    mkdtempSync,
    readFileSync,
    readdirSync,
    rmSync,
} from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const snapshot = (directory, prefix = '') => {
    const files = new Map()
    if (!existsSync(directory)) return files
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
        const relative = prefix + entry.name
        const filename = path.join(directory, entry.name)
        if (entry.isDirectory()) {
            for (const [name, bytes] of snapshot(filename, relative + '/')) {
                files.set(name, bytes)
            }
        } else if (entry.isFile()) {
            files.set(relative, readFileSync(filename))
        } else {
            throw new Error(`Build output must contain regular files: ${filename}`)
        }
    }
    return files
}

export const verifyPackageBuild = (
    directory,
    build = () =>
        execFileSync('npm', ['run', 'build'], {
            cwd: directory,
            stdio: 'inherit',
        }),
) => {
    const output = path.join(directory, 'publishes/build')
    const committed = snapshot(output)
    const temporary = mkdtempSync(path.join(os.tmpdir(), 'capell-build-check-'))
    const saved = path.join(temporary, 'original')
    let built = false
    let staged = false
    let prepared = false
    try {
        // Build from an empty directory: otherwise orphaned artefacts survive
        // compilers which overwrite their outputs without clearing the directory.
        if (existsSync(output)) {
            cpSync(output, saved, { recursive: true })
            staged = true
        }
        rmSync(output, { recursive: true, force: true })
        mkdirSync(output, { recursive: true })
        prepared = true
        build()
        const rebuilt = snapshot(output)
        if (rebuilt.size === 0) {
            throw new Error('The package build produced no assets.')
        }
        built = true
        const differences = [
            ...new Set([...committed.keys(), ...rebuilt.keys()]),
        ]
            .filter(
                (name) =>
                    !committed
                        .get(name)
                        ?.equals(rebuilt.get(name) ?? Buffer.alloc(0)) ||
                    !rebuilt.has(name),
            )
            .sort()
        if (differences.length > 0) {
            throw new Error(
                `Published assets differ from the build. Regenerate and commit publishes/build:\n${differences.join('\n')}`,
            )
        }
    } catch (error) {
        if (!built && (staged || prepared)) {
            rmSync(output, { recursive: true, force: true })
            if (existsSync(saved)) {
                cpSync(saved, output, { recursive: true })
                rmSync(saved, { recursive: true })
            }
        }
        throw error
    } finally {
        if (built || !staged || !existsSync(saved)) {
            rmSync(temporary, { recursive: true, force: true })
        }
    }
}

if (
    process.argv[1] &&
    path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)
) {
    try {
        verifyPackageBuild(path.resolve(process.argv[2] ?? '.'))
    } catch (error) {
        console.error(error.message)
        process.exitCode = 1
    }
}
