import assert from 'node:assert/strict'
import {
    mkdtempSync,
    mkdirSync,
    readFileSync,
    rmSync,
    writeFileSync,
} from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import test from 'node:test'
import { verifyPackageBuild } from '../../scripts/check-package-build.mjs'

const emit = (output, runtime = 'old') => {
    mkdirSync(path.join(output, 'assets'), { recursive: true })
    writeFileSync(path.join(output, 'assets/runtime.js'), runtime)
    writeFileSync(
        path.join(output, 'manifest.json'),
        '{"runtime":"assets/runtime.js"}',
    )
}

const fixture = (t) => {
    const root = mkdtempSync(path.join(os.tmpdir(), 'capell-build-test-'))
    t.after(() => rmSync(root, { recursive: true, force: true }))
    const output = path.join(root, 'publishes/build')
    emit(output)
    return { root, output }
}

test('accepts byte-identical rebuilt assets', (t) => {
    const { root, output } = fixture(t)
    verifyPackageBuild(root, () => emit(output))
})

for (const change of ['changed', 'added', 'deleted', 'renamed']) {
    test(`rejects ${change} build output`, (t) => {
        const { root, output } = fixture(t)
        assert.throws(
            () =>
                verifyPackageBuild(root, () => {
                    emit(output, change === 'changed' ? 'new' : 'old')
                    if (change === 'added' || change === 'renamed') {
                        writeFileSync(path.join(output, 'assets/new.js'), '')
                    }
                    if (change === 'deleted' || change === 'renamed') {
                        rmSync(path.join(output, 'assets/runtime.js'))
                    }
                }),
            /Published assets differ/,
        )
    })
}

test('restores original artefacts after a compiler failure', (t) => {
    const { root, output } = fixture(t)
    assert.throws(
        () =>
            verifyPackageBuild(root, () => {
                emit(output, 'partial')
                throw new Error('compiler failed')
            }),
        /compiler failed/,
    )
    assert.equal(readFileSync(path.join(output, 'assets/runtime.js'), 'utf8'), 'old')
})

test('rejects an empty build', (t) => {
    const { root } = fixture(t)
    assert.throws(
        () => verifyPackageBuild(root, () => {}),
        /produced no assets/,
    )
})

test('rejects an orphaned published file that the compiler no longer produces', (t) => {
    const { root, output } = fixture(t)
    writeFileSync(path.join(output, 'orphan.js'), 'unreviewed')
    assert.throws(
        () => verifyPackageBuild(root, () => emit(output)),
        /Published assets differ/,
    )
})
