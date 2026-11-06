import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import keyboardShortcuts from '../../resources/js/components/keyboard-shortcuts.js'

for (const source of ['source', 'published']) {
    const { default: createShortcuts } =
        source === 'source'
            ? { default: keyboardShortcuts }
            : await import('../../publishes/build/js/components/keyboard-shortcuts.js')

    test(`${source}: navigation fails closed without a palette or command ID`, () => {
        globalThis.document = { querySelector: () => null }
        globalThis.window = { location: { pathname: '/admin', href: '' } }
        try {
            createShortcuts([]).executeShortcut({
                action: 'navigate',
                target: 'pages',
            })
            assert.equal(window.location.href, '')
        } finally {
            delete globalThis.window
            delete globalThis.document
        }
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

test('modified keystrokes cannot start or complete global shortcuts', () => {
    globalThis.HTMLInputElement = class {}
    globalThis.HTMLTextAreaElement = class {}
    globalThis.HTMLSelectElement = class {}
    globalThis.HTMLElement = class {}
    try {
        const state = keyboardShortcuts([
            { key: 'p', action: 'focus', target: '#search' },
            { sequence: 'g p', action: 'navigate', target: 'pages' },
        ])
        let count = 0
        state.executeShortcut = () => count++
        for (const modifier of ['ctrlKey', 'metaKey', 'altKey']) {
            state.handleKeydown({
                key: 'p',
                target: null,
                [modifier]: true,
                preventDefault() {},
            })
            state.handleKeydown({
                key: 'g',
                target: null,
                [modifier]: true,
                preventDefault() {},
            })
            assert.equal(state.pendingKey, null)
            state.handleKeydown({ key: 'g', target: null, preventDefault() {} })
            state.handleKeydown({
                key: 'p',
                target: null,
                [modifier]: true,
                preventDefault() {},
            })
            assert.equal(count, 0)
            state.destroy()
        }
    } finally {
        for (const name of [
            'HTMLInputElement',
            'HTMLTextAreaElement',
            'HTMLSelectElement',
            'HTMLElement',
        ])
            delete globalThis[name]
    }
})

test('navigation shortcuts recheck the command on the server', () => {
    const calls = []
    globalThis.document = {
        querySelector: () => ({ getAttribute: () => 'palette-id' }),
    }
    globalThis.window = {
        Livewire: { find: () => ({ call: (...args) => calls.push(args) }) },
        location: { pathname: '/admin', href: '' },
    }
    try {
        keyboardShortcuts([]).executeShortcut({
            action: 'navigate',
            target: 'pages',
            commandId: 'capell.pages',
            url: '/admin/pages',
        })
        assert.deepEqual(calls, [['runCommand', 'capell.pages']])
        assert.equal(window.location.href, '')
    } finally {
        delete globalThis.window
        delete globalThis.document
    }
})
