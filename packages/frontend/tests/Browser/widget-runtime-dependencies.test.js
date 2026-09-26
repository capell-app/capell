import assert from 'node:assert/strict'
import path from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'

const runtimeUrl = pathToFileURL(
    path.resolve(
        path.dirname(fileURLToPath(import.meta.url)),
        '../../resources/js/widget-runtime.js',
    ),
).href

test('every widget graph waits for a shared dependency while loading it once', async () => {
    const originalCustomEvent = global.CustomEvent
    const originalDocument = global.document
    const originalElement = global.Element
    const originalWindow = global.window
    const starts = []
    let dependencyReady = false
    let dependencyRequests = 0

    class FakeElement {
        constructor() {
            this.listeners = new Map()
            this.src = ''
        }

        addEventListener(type, listener) {
            this.listeners.set(type, listener)
        }

        dispatchEvent() {}

        setAttribute() {}

        remove() {}

        loaded() {
            this.listeners.get('load')?.()
        }
    }

    const activations = [
        {
            target: 'first-widget',
            loading: 'interaction',
            layers: [
                [
                    {
                        token: 'aaaaaaaaaaaaaaaaaaaaaaaa',
                        kind: 'classic-script',
                        url: 'https://capell.test/assets/shared.js',
                    },
                ],
                [
                    {
                        token: 'bbbbbbbbbbbbbbbbbbbbbbbb',
                        kind: 'classic-script',
                        url: 'https://capell.test/assets/first.js',
                    },
                ],
            ],
        },
        {
            target: 'second-widget',
            loading: 'interaction',
            layers: [
                [
                    {
                        token: 'aaaaaaaaaaaaaaaaaaaaaaaa',
                        kind: 'classic-script',
                        url: 'https://capell.test/assets/shared.js',
                    },
                ],
                [
                    {
                        token: 'cccccccccccccccccccccccc',
                        kind: 'classic-script',
                        url: 'https://capell.test/assets/second.js',
                    },
                ],
            ],
        },
    ]

    const appendResource = (element) => {
        if (element.src.endsWith('/shared.js')) {
            dependencyRequests += 1
            setTimeout(() => {
                dependencyReady = true
                element.loaded()
            }, 25)

            return element
        }

        starts.push({
            resource: new URL(element.src).pathname,
            dependencyReady,
        })
        queueMicrotask(() => element.loaded())

        return element
    }

    global.Element = FakeElement
    global.CustomEvent = class {
        constructor(type, options) {
            this.type = type
            this.detail = options?.detail
        }
    }
    global.window = {
        __capellWidgetResourceStates: new Map(),
        addEventListener() {},
        location: { origin: 'https://capell.test' },
    }
    global.document = {
        baseURI: 'https://capell.test/base',
        readyState: 'loading',
        addEventListener() {},
        body: { appendChild: appendResource },
        head: { appendChild: appendResource },
        createElement: () => new FakeElement(),
        querySelector: () => ({ textContent: JSON.stringify(activations) }),
        querySelectorAll: () => [],
    }

    try {
        await import(`${runtimeUrl}?dependency-order=${Date.now()}`)

        const target = new FakeElement()
        await window.CapellWidgetRuntime.retryResources(target, [
            'first-widget',
            'second-widget',
        ])

        assert.equal(dependencyRequests, 1)
        assert.equal(starts.length, 2)
        assert.equal(
            starts.every(({ dependencyReady: ready }) => ready),
            true,
        )
    } finally {
        if (originalCustomEvent === undefined) delete global.CustomEvent
        else global.CustomEvent = originalCustomEvent

        if (originalDocument === undefined) delete global.document
        else global.document = originalDocument

        if (originalElement === undefined) delete global.Element
        else global.Element = originalElement

        if (originalWindow === undefined) delete global.window
        else global.window = originalWindow
    }
})
