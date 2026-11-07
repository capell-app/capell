import { execFile } from 'node:child_process'
import { createHash } from 'node:crypto'
import { createReadStream } from 'node:fs'
import { readFile } from 'node:fs/promises'
import { isAbsolute } from 'node:path'
import { promisify } from 'node:util'

const execFileAsync = promisify(execFile)

function requiredEnvironment(name, pattern = null) {
    const value = process.env[name]
    if (
        typeof value !== 'string' ||
        value === '' ||
        (pattern && !pattern.test(value))
    ) {
        throw new Error(
            `${name} is missing or invalid for frozen-runtime verification.`,
        )
    }
    return value
}

async function sha256File(file) {
    const hash = createHash('sha256')
    for await (const chunk of createReadStream(file)) hash.update(chunk)
    return hash.digest('hex')
}

export async function verifyVoyagerRuntime() {
    const expectedImage = requiredEnvironment(
        'CAPELL_VOYAGER_EXPECTED_IMAGE_ID',
        /^sha256:[a-f0-9]{64}$/u,
    )
    const expectedRevision = requiredEnvironment(
        'CAPELL_VOYAGER_EXPECTED_SOURCE_REF',
        /^[a-f0-9]{40}$/u,
    )
    const expectedManifest = requiredEnvironment(
        'CAPELL_VOYAGER_EXPECTED_MANIFEST_SHA256',
        /^[a-f0-9]{64}$/u,
    )
    const expectedArchive = requiredEnvironment(
        'CAPELL_VOYAGER_EXPECTED_ARCHIVE_SHA256',
        /^[a-f0-9]{64}$/u,
    )
    const manifestPath = requiredEnvironment('CAPELL_VOYAGER_RUNTIME_MANIFEST')
    const archivePath = requiredEnvironment('CAPELL_VOYAGER_SOURCE_ARCHIVE')
    const schemaPath = requiredEnvironment('CAPELL_VOYAGER_SCHEMA_FILE')
    const control = requiredEnvironment('CAPELL_VOYAGER_FIXTURE_CONTROL')
    const containerRoot = requiredEnvironment('CAPELL_VOYAGER_CONTAINER_ROOT')
    if (
        ![manifestPath, archivePath, schemaPath, control, containerRoot].every(
            isAbsolute,
        )
    ) {
        throw new Error(
            'Frozen-runtime file/control/container paths must be absolute.',
        )
    }
    const [manifestBytes, manifestSha256, archiveSha256, schemaSha256] =
        await Promise.all([
            readFile(manifestPath),
            sha256File(manifestPath),
            sha256File(archivePath),
            sha256File(schemaPath),
        ])
    const manifest = JSON.parse(manifestBytes.toString('utf8'))
    if (
        manifestSha256 !== expectedManifest ||
        archiveSha256 !== expectedArchive ||
        manifest.legacy_ref !== expectedRevision ||
        manifest.schema_ref !== expectedRevision ||
        manifest.legacy_archive_sha256 !== expectedArchive ||
        manifest.fixture_schema_sha256 !== schemaSha256
    ) {
        throw new Error(
            'Frozen-runtime source/archive/schema identity does not match the expected evidence.',
        )
    }
    let actual
    try {
        const { stdout } = await execFileAsync(control, ['inspect'], {
            encoding: 'utf8',
            timeout: 15_000,
            maxBuffer: 128 * 1024,
        })
        actual = JSON.parse(stdout)
    } catch {
        throw new Error(
            'Frozen-runtime inspection failed; the control must return its non-sensitive inspection contract.',
        )
    }
    const allowedRuntimeMounts = new Set([
        `${containerRoot.replace(/\/$/u, '')}/storage`,
        `${containerRoot.replace(/\/$/u, '')}/bootstrap/cache`,
    ])
    if (
        actual.imageId !== expectedImage ||
        actual.sourceRevision !== expectedRevision ||
        actual.manifestSha256 !== expectedManifest ||
        actual.running !== true ||
        actual.health !== 'healthy' ||
        actual.logChannel !== 'stderr' ||
        !Array.isArray(actual.mounts) ||
        actual.mounts.some(
            (mount) =>
                mount.type !== 'tmpfs' ||
                !allowedRuntimeMounts.has(mount.destination),
        )
    ) {
        throw new Error(
            'Frozen-runtime image, embedded manifest, logging or mount isolation could not be verified.',
        )
    }
    return Object.freeze({
        name: 'verified-frozen-voyager-runtime',
        passed: true,
        sourceRevision: expectedRevision,
        imageId: expectedImage,
        archiveSha256,
        manifestSha256,
        schemaSha256,
        sourceBindMounts: false,
    })
}

export default async function preCapture({ config, entries }) {
    const origin = new URL(requiredEnvironment('CAPELL_VOYAGER_CAPTURE_ORIGIN'))
        .origin
    const control = requiredEnvironment('CAPELL_VOYAGER_FIXTURE_CONTROL')
    if (
        config.serve?.command !== control ||
        JSON.stringify(config.serve?.args) !== JSON.stringify(['serve']) ||
        config.serve?.readyUrl !== `${origin}/fpm-ping` ||
        config.reuseApp ||
        config.frontendUrl !== origin ||
        config.adminUrl !== `${origin}/admin` ||
        entries.length === 0 ||
        entries.some(
            (entry) =>
                entry.scenario !== 'voyager-guide-admin' ||
                entry.surface !== 'admin' ||
                entry.user !== 'voyager',
        )
    ) {
        throw new Error(
            'Voyager guide capture requires its explicit local profile, selected admin entries and a foreground app server.',
        )
    }
    await verifyVoyagerRuntime()
}
