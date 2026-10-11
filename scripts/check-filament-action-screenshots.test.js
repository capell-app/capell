const assert = require('node:assert/strict')
const { spawnSync } = require('node:child_process')
const fs = require('node:fs')
const os = require('node:os')
const path = require('node:path')
const test = require('node:test')

function check(t, source) {
    const root = fs.mkdtempSync(
        path.join(os.tmpdir(), 'capell-action-coverage-'),
    )
    t.after(() => fs.rmSync(root, { recursive: true, force: true }))
    const file = path.join(
        root,
        'packages/admin/src/Filament/Pages/Example.php',
    )
    fs.mkdirSync(path.dirname(file), { recursive: true })
    fs.writeFileSync(file, source)
    return spawnSync(
        process.execPath,
        [path.join(__dirname, 'check-filament-action-screenshots.js')],
        {
            cwd: root,
            env: { ...process.env, CAPELL_PACKAGES_REPO: '' },
            encoding: 'utf8',
        },
    )
}

test('ignores imported and fully qualified backend action factories', (t) => {
    const result = check(
        t,
        `<?php
namespace Capell\\Admin\\Filament\\Pages;
use Capell\\Admin\\Actions\\RestorePageCascadeAction as RestoreAction;
RestoreAction::make()->handle();
\\Capell\\Admin\\Actions\\RestorePageCascadeAction::make()->handle();`,
    )
    assert.equal(result.status, 0, result.stderr)
})

test('retains aliased custom Filament action coverage requirements', (t) => {
    const result = check(
        t,
        `<?php
use Filament\\Actions\\Action as Restore;
Restore::make('restore')->action(fn () => null);`,
    )
    assert.equal(result.status, 1)
    assert.match(result.stderr, /custom action maker/)
})

test('backend factories do not truncate standard UI action behaviour', (t) => {
    const result = check(
        t,
        `<?php
use Filament\\Actions\\EditAction;
use Capell\\Admin\\Actions\\RestorePageCascadeAction;
EditAction::make()->label(RestorePageCascadeAction::make()->label())->modalHeading('Confirm');`,
    )
    assert.equal(result.status, 1)
    assert.match(result.stderr, /custom modalHeading/)
    assert.doesNotMatch(result.stderr, /custom action maker/)
})

test('retains fully qualified custom Filament action coverage requirements', (t) => {
    const result = check(
        t,
        `<?php
\\Capell\\Admin\\Filament\\Actions\\PublishAction::make('publish');`,
    )
    assert.equal(result.status, 1)
    assert.match(result.stderr, /custom action maker/)
})

test('requires screenshots for customised standard Filament actions', (t) => {
    const result = check(
        t,
        `<?php
use Filament\\Actions\\EditAction;
EditAction::make()->modalHeading('Confirm');`,
    )
    assert.equal(result.status, 1)
    assert.match(result.stderr, /custom modalHeading/)
})

test('does not attach later unrelated methods to a standard UI action', (t) => {
    const result = check(
        t,
        `<?php
use Filament\\Actions\\EditAction;
EditAction::make();
$backend->before(fn () => null);`,
    )
    assert.equal(result.status, 0, result.stderr)
})
