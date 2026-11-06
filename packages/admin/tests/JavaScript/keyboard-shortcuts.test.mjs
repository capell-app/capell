import assert from 'node:assert/strict'
import test from 'node:test'
import keyboardShortcuts from '../../resources/js/components/keyboard-shortcuts.js'

for (const source of ['source', 'published']) {
    const { default: createShortcuts } =
        source === 'source'
            ? { default: keyboardShortcuts }
            : await import('../../publishes/build/js/components/keyboard-shortcuts.js')

    test(`${source}: navigation uses the authorised destination when the command ID differs`, () => {
        globalThis.window = { location: { pathname: '/admin/pages', href: '' } }
        createShortcuts([]).executeShortcut({
            action: 'navigate',
            target: 'extension.report',
            url: '/admin/reports/weekly',
        })
        assert.equal(window.location.href, '/admin/reports/weekly')
        delete globalThis.window
    })

    test(`${source}: navigation retains the legacy panel-relative fallback`, () => {
        globalThis.window = {
            location: { pathname: '/workspace/pages', href: '' },
        }
        createShortcuts([]).executeShortcut({
            action: 'navigate',
            target: 'themes',
        })
        assert.equal(window.location.href, '/workspace/themes')
        delete globalThis.window
    })
}
