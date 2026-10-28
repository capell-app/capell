const assert = require('node:assert/strict')
const { spawnSync } = require('node:child_process')
const fs = require('node:fs')
const os = require('node:os')
const path = require('node:path')
const test = require('node:test')

const hook = path.join(__dirname, '../.pre-commit/prettier.sh')

test('formats supplied paths without staging changes or contending on the index', (t) => {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'capell-prettier-hook-'))
    t.after(() => fs.rmSync(root, { recursive: true, force: true }))
    const git = (...args) => {
        const result = spawnSync('git', args, { cwd: root, encoding: 'utf8' })
        assert.equal(result.status, 0, result.stderr)
        return result.stdout
    }
    git('init', '--quiet')
    fs.writeFileSync(path.join(root, 'selected file.md'), 'original\n')
    fs.writeFileSync(path.join(root, 'unrelated.md'), 'untouched\n')
    git('add', 'selected file.md')
    const index = fs.readFileSync(path.join(root, '.git/index'))
    const bin = path.join(root, 'node_modules/.bin')
    fs.mkdirSync(bin, { recursive: true })
    fs.writeFileSync(
        path.join(bin, 'prettier'),
        '#!/usr/bin/env node\nconst fs = require("node:fs"); for (const file of process.argv.slice(2).filter(arg => !arg.startsWith("--"))) fs.writeFileSync(file, "formatted\\n");\n',
        { mode: 0o755 },
    )
    // Another index owner must not prevent a formatter from doing its own work.
    fs.writeFileSync(
        path.join(root, '.git/index.lock'),
        'owned by another writer\n',
    )
    const result = spawnSync('bash', [hook, 'selected file.md'], {
        cwd: root,
        encoding: 'utf8',
    })
    assert.equal(result.status, 0, result.stderr)
    assert.deepEqual(fs.readFileSync(path.join(root, '.git/index')), index)
    assert.equal(
        fs.readFileSync(path.join(root, 'selected file.md'), 'utf8'),
        'formatted\n',
    )
    assert.equal(
        fs.readFileSync(path.join(root, 'unrelated.md'), 'utf8'),
        'untouched\n',
    )
    assert.equal(git('show', ':selected file.md'), 'original\n')
})

test('does nothing when pre-commit supplies no paths', () => {
    const result = spawnSync('bash', [hook], { encoding: 'utf8' })
    assert.equal(result.status, 0, result.stderr)
})
