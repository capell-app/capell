import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
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

    test(`${source}: remount and SPA navigation retain one keyboard listener`, () => {
        class Surface extends EventTarget {
            listeners = new Map()
            addEventListener(type, listener, options) {
                if (!this.listeners.has(type))
                    this.listeners.set(type, new Set())
                this.listeners.get(type).add(listener)
                super.addEventListener(type, listener, options)
            }
            removeEventListener(type, listener, options) {
                this.listeners.get(type)?.delete(listener)
                super.removeEventListener(type, listener, options)
            }
            count(type) {
                return this.listeners.get(type)?.size ?? 0
            }
        }
        globalThis.window = new Surface()
        globalThis.document = new Surface()
        globalThis.HTMLInputElement = class {}
        globalThis.HTMLTextAreaElement = class {}
        globalThis.HTMLSelectElement = class {}
        globalThis.HTMLElement = class {}
        try {
            const first = createShortcuts([
                { sequence: 'g t', action: 'navigate', target: 'themes' },
            ])
            first.init()
            first.init()
            assert.equal(window.count('keydown'), 1)
            first.handleKeydown({ key: 'g', target: null })
            assert.equal(first.pendingKey, 'g')
            const navigation = new Event('livewire:navigate', {
                cancelable: true,
            })
            document.addEventListener(
                'livewire:navigate',
                (event) => event.preventDefault(),
                { once: true },
            )
            assert.equal(document.dispatchEvent(navigation), false)
            assert.equal(window.count('keydown'), 1)
            let cancelledExecutions = 0
            first.executeShortcut = () => cancelledExecutions++
            const completeChord = new Event('keydown', { cancelable: true })
            completeChord.key = 't'
            window.dispatchEvent(completeChord)
            assert.equal(cancelledExecutions, 1)
            first.handleKeydown({ key: 'g', target: null })
            document.dispatchEvent(new Event('livewire:navigating'))
            assert.equal(window.count('keydown'), 0)
            assert.equal(first.pendingKey, null)
            assert.equal(first.pendingTimer, null)
            first.destroy()
            assert.equal(document.count('livewire:navigating'), 0)

            const next = createShortcuts([
                { key: '/', action: 'focus', target: '#search' },
            ])
            let executions = 0
            next.executeShortcut = () => executions++
            next.init()
            assert.equal(window.count('keydown'), 1)
            const keydown = new Event('keydown', { cancelable: true })
            keydown.key = '/'
            window.dispatchEvent(keydown)
            assert.equal(executions, 1)
            next.destroy()
            assert.equal(window.count('keydown'), 0)
            assert.equal(document.count('livewire:navigating'), 0)
        } finally {
            for (const name of [
                'window',
                'document',
                'HTMLInputElement',
                'HTMLTextAreaElement',
                'HTMLSelectElement',
                'HTMLElement',
            ]) {
                delete globalThis[name]
            }
        }
    })
}

test('the published keyboard module is byte-identical to the source', () => {
    assert.deepEqual(
        readFileSync(
            new URL(
                '../../publishes/build/js/components/keyboard-shortcuts.js',
                import.meta.url,
            ),
        ),
        readFileSync(
            new URL(
                '../../resources/js/components/keyboard-shortcuts.js',
                import.meta.url,
            ),
        ),
    )
})
