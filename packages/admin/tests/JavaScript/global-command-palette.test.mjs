import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const template = readFileSync(
    new URL(
        '../../resources/views/livewire/global-command-palette.blade.php',
        import.meta.url,
    ),
    'utf8',
)
const expression = template
    .match(/x-data="([\s\S]*?)"\s+x-on:/)[1]
    .replace("@entangle('open')", 'false')

test('the empty state reacts to morphed commands without another keystroke', () => {
    let observer
    class MutationObserver {
        constructor(callback) {
            this.callback = callback
            observer = this
        }
        observe(target, options) {
            this.target = target
            this.options = options
        }
        disconnect() {
            this.target = null
        }
    }
    let commands = [{ dataset: { label: 'Media', description: 'Find images' } }]
    const results = { querySelectorAll: () => commands }
    let emptyStateVisible
    const state = new Proxy(
        new Function('MutationObserver', `return (${expression})`)(
            MutationObserver,
        ),
        {
            set(target, key, value) {
                Reflect.set(target, key, value)
                if (key === 'commands' || key === 'query') {
                    emptyStateVisible = !state.hasResults()
                }
                return true
            },
        },
    )
    state.$refs = { results }
    state.$watch = () => {}
    state.init()
    // Alpine runs the results element's x-init after the root initialises.
    const resultsInit =
        template.match(/x-ref="results"\s+x-init="([^"]+)"/)?.[1] ?? ''
    new Function('$el', `with (this) { ${resultsInit} }`).call(state, results)
    state.query = 'images'
    assert.equal(emptyStateVisible, false)

    const morph = (nextCommands, mutation) => {
        commands = nextCommands
        if (observer?.target) observer.callback([mutation])
    }
    morph([], { type: 'childList' })
    assert.equal(
        emptyStateVisible,
        true,
        'removing the last matching command shows the empty state',
    )
    morph([{ dataset: { label: 'Images', description: '' } }], {
        type: 'childList',
    })
    assert.equal(
        emptyStateVisible,
        false,
        'adding a matching command hides the empty state',
    )
    morph([{ dataset: { label: 'Pages', description: '' } }], {
        type: 'attributes',
        attributeName: 'data-label',
    })
    assert.equal(
        emptyStateVisible,
        true,
        'a changed label updates the empty state',
    )
    morph([{ dataset: { label: 'Pages', description: 'Images' } }], {
        type: 'attributes',
        attributeName: 'data-description',
    })
    assert.equal(
        emptyStateVisible,
        false,
        'a changed description updates the empty state',
    )
    assert.equal(observer.options.childList, true)
    assert.equal(observer.options.subtree, true)
    assert.deepEqual(observer.options.attributeFilter, [
        'data-label',
        'data-description',
    ])
    state.destroy()
    assert.equal(observer.target, null, 'destroy disconnects the observer')
})
