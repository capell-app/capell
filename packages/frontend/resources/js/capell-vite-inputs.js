import fs from 'node:fs'
import path from 'node:path'

export function capellViteInputs(applicationRoot = process.cwd()) {
    const manifestPath = path.join(
        applicationRoot,
        'bootstrap/cache/capell-vite-inputs.json',
    )

    if (!fs.existsSync(manifestPath)) {
        return []
    }

    const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'))

    return Array.isArray(manifest.inputs)
        ? manifest.inputs.filter((input) => typeof input === 'string')
        : []
}
