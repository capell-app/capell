import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test, { after, before, beforeEach } from 'node:test'

import { chromium } from 'playwright'

const moduleSource = await readFile(
    new URL('../../resources/js/deferred-fragments.js', import.meta.url),
    'utf8',
)
const moduleUrl = `data:text/javascript;base64,${Buffer.from(moduleSource).toString('base64')}`

let browser
let page

before(async () => {
    browser = await chromium.launch({
        headless: true,
        channel: process.env.PLAYWRIGHT_BROWSER_CHANNEL || 'chrome',
    })
})

after(async () => {
    await browser?.close()
})

beforeEach(async () => {
    page = await browser.newPage()
    await page.setContent(`
        <main>
            <section
                data-deferred-fragment
                data-deferred-fragment-key="homepage-proof"
                data-deferred-fragment-url="/_capell/fragments/homepage-proof"
                aria-busy="true"
                style="min-height: 34rem"
            ><div data-cr-skel="band"><a href="/placeholder">Loading</a></div></section>
        </main>
    `)
    await page.evaluate(async (url) => {
        const { createDeferredFragments } = await import(url)
        const stored = new Map()
        const observers = []
        const activeListeners = new Map()
        const idleCallbacks = []
        const listenerCounts = new Map()
        const listenerRemovalCounts = new Map()
        const pendingResponses = []
        const nativeAddEventListener = window.addEventListener.bind(window)
        const nativeRemoveEventListener =
            window.removeEventListener.bind(window)

        window.addEventListener = (type, listener, options) => {
            listenerCounts.set(type, (listenerCounts.get(type) ?? 0) + 1)
            const listeners = activeListeners.get(type) ?? new Set()
            listeners.add(listener)
            activeListeners.set(type, listeners)
            nativeAddEventListener(type, listener, options)
        }

        window.removeEventListener = (type, listener, options) => {
            listenerRemovalCounts.set(
                type,
                (listenerRemovalCounts.get(type) ?? 0) + 1,
            )
            activeListeners.get(type)?.delete(listener)
            nativeRemoveEventListener(type, listener, options)
        }

        Object.defineProperty(window, 'sessionStorage', {
            configurable: true,
            value: {
                getItem: (key) => stored.get(key) ?? null,
                removeItem: (key) => stored.delete(key),
                setItem: (key, value) => stored.set(key, value),
            },
        })

        window.IntersectionObserver = class {
            constructor(callback) {
                this.callback = callback
                this.disconnectCount = 0
                this.targets = new Set()
                observers.push(this)
            }

            disconnect() {
                this.disconnectCount += 1
                this.targets.clear()
            }

            observe(target) {
                this.targets.add(target)
            }

            unobserve(target) {
                this.targets.delete(target)
            }

            intersect(target) {
                if (!this.targets.has(target)) return

                this.callback([{ isIntersecting: true, target }])
            }
        }

        window.__deferredTest = {
            activeListeners,
            autoRunIdle: true,
            fetchCount: 0,
            idleCallbacks,
            initializeCount: 0,
            loadedEventCount: 0,
            listenerCounts,
            listenerRemovalCounts,
            observers,
            pendingResponses,
            responses: [],
            stored,
        }

        document.addEventListener('capell:deferred-fragment-loaded', () => {
            window.__deferredTest.loadedEventCount += 1
        })

        window.__deferredTest.initialize = createDeferredFragments({
            fetchHtmlFragment: async () => {
                window.__deferredTest.fetchCount += 1
                const response = window.__deferredTest.responses.shift()

                if (response instanceof Error) throw response

                if (response === '__PENDING__') {
                    return new Promise((resolve, reject) => {
                        window.__deferredTest.pendingResponses.push({
                            reject,
                            resolve,
                        })
                    })
                }

                return typeof response === 'string'
                    ? {
                          html: response,
                          cacheable: true,
                          cacheControl: 'public, max-age=1800',
                      }
                    : response
            },
            initializeFragment: () => {
                window.__deferredTest.initializeCount += 1
            },
            onError: (fragment) => {
                const fallback = fragment.querySelector(
                    'template[data-fragment-fallback]',
                )
                const content = fallback?.content.cloneNode(true) ?? null
                fragment.replaceChildren(
                    ...(content ? Array.from(content.childNodes) : []),
                )
                fragment.style.removeProperty('min-height')
                fragment.hidden = content === null
                if (content !== null) {
                    fragment.dataset.deferredFragmentFallback = 'true'
                    window.__deferredTest.initializeCount += 1
                }
            },
            onIdle: (callback) => {
                window.__deferredTest.idleCallbacks.push(callback)

                if (window.__deferredTest.autoRunIdle) callback()
            },
            retryDelay: 1,
        })
    }, moduleUrl)
})

const runScenario = (
    responses,
    {
        autoRunIdle = true,
        cachedHtml,
        cachedStoredAt,
        intentEvent,
        strategy,
    } = {},
) =>
    page.evaluate(
        async ({
            cachedHtml: html,
            cachedStoredAt: storedAt,
            autoRunIdle: shouldAutoRunIdle,
            intentEvent: nextIntentEvent,
            responses: nextResponses,
            strategy: loadStrategy,
        }) => {
            const testState = window.__deferredTest
            const fragment = document.querySelector('[data-deferred-fragment]')

            testState.autoRunIdle = shouldAutoRunIdle

            if (loadStrategy) {
                fragment.dataset.deferredFragmentStrategy = loadStrategy
            }

            testState.responses.push(
                ...nextResponses.map((response) =>
                    response === '__ERROR__'
                        ? new Error('Network failure')
                        : response,
                ),
            )

            if (html !== undefined) {
                testState.stored.set(
                    'capell:deferred-fragment:v2:homepage-proof',
                    JSON.stringify({
                        html,
                        storedAt: storedAt ?? Date.now(),
                        cacheable: true,
                        cacheControl: 'public, max-age=1800',
                    }),
                )
            }

            testState.initialize()
            testState.initialize()

            if (nextIntentEvent) {
                window.dispatchEvent(new Event(nextIntentEvent))
            }

            testState.observers.forEach((observer) => {
                observer.intersect(fragment)
                observer.intersect(fragment)
            })

            const terminal = () =>
                fragment.dataset.deferredFragmentLoaded === 'true' ||
                fragment.dataset.deferredFragmentState === 'error'

            for (let attempt = 0; attempt < 100 && !terminal(); attempt += 1) {
                await new Promise((resolve) => setTimeout(resolve, 2))
            }

            return {
                ariaBusy: fragment.getAttribute('aria-busy'),
                fetchCount: testState.fetchCount,
                hidden: fragment.hidden,
                html: fragment.innerHTML,
                initializeCount: testState.initializeCount,
                loaded: fragment.dataset.deferredFragmentLoaded ?? null,
                loadedEventCount: testState.loadedEventCount,
                activeListenerCounts: Object.fromEntries(
                    Array.from(
                        testState.activeListeners,
                        ([type, listeners]) => [type, listeners.size],
                    ),
                ),
                listenerCounts: Object.fromEntries(testState.listenerCounts),
                listenerRemovalCounts: Object.fromEntries(
                    testState.listenerRemovalCounts,
                ),
                minHeight: fragment.style.minHeight,
                observerCount: testState.observers.length,
                observerDisconnectCount: testState.observers.reduce(
                    (count, observer) => count + observer.disconnectCount,
                    0,
                ),
                observerTargetCount: testState.observers.reduce(
                    (count, observer) => count + observer.targets.size,
                    0,
                ),
                state: fragment.dataset.deferredFragmentState ?? null,
                storedHtml: JSON.parse(
                    testState.stored.get(
                        'capell:deferred-fragment:v2:homepage-proof',
                    ) ?? 'null',
                )?.html,
            }
        },
        {
            autoRunIdle,
            cachedHtml,
            cachedStoredAt,
            intentEvent,
            responses,
            strategy,
        },
    )

test('a valid network fragment applies once across repeated initialization and intersections', async () => {
    const result = await runScenario(['<article><h2>Proof</h2></article>'])

    assert.equal(result.fetchCount, 1)
    assert.equal(result.observerCount, 1)
    assert.equal(result.observerDisconnectCount, 1)
    assert.equal(result.observerTargetCount, 0)
    assert.equal(result.initializeCount, 1)
    assert.equal(result.loadedEventCount, 1)
    assert.equal(result.loaded, 'true')
    assert.equal(result.state, 'loaded')
    assert.equal(result.ariaBusy, null)
    assert.equal(result.hidden, false)
    assert.match(result.html, /<h2>Proof<\/h2>/)
    assert.equal(result.storedHtml, '<article><h2>Proof</h2></article>')
})

test('a valid cached fragment applies once without a network request', async () => {
    const result = await runScenario([], {
        cachedHtml: '<article><h2>Cached proof</h2></article>',
    })

    assert.equal(result.fetchCount, 0)
    assert.equal(result.initializeCount, 1)
    assert.equal(result.loadedEventCount, 1)
    assert.equal(result.loaded, 'true')
    assert.match(result.html, /Cached proof/)
})

test('one network failure retries once and then applies valid content', async () => {
    const result = await runScenario([
        '__ERROR__',
        '<article><h2>Recovered proof</h2></article>',
    ])

    assert.equal(result.fetchCount, 2)
    assert.equal(result.loaded, 'true')
    assert.equal(result.loadedEventCount, 1)
    assert.match(result.html, /Recovered proof/)
})

test('an empty response retries once before applying a fragment', async () => {
    const empty = await runScenario([
        '   ',
        '<article><h2>Recovered from empty</h2></article>',
    ])

    assert.equal(empty.fetchCount, 2)
    assert.equal(empty.loadedEventCount, 1)
    assert.match(empty.html, /Recovered from empty/)
})

test('a full-document response retries once before applying a fragment', async () => {
    const result = await runScenario([
        '\uFEFF  <!-- proxy --> <?xml version="1.0"?> <!doctype html><html><body>Error page</body></html>',
        '<article><h2>Recovered from document</h2></article>',
    ])

    assert.equal(result.fetchCount, 2)
    assert.equal(result.loadedEventCount, 1)
    assert.match(result.html, /Recovered from document/)
    assert.doesNotMatch(result.html, /Error page/)
})

test('a newline-delimited doctype response retries before applying a fragment', async () => {
    const result = await runScenario([
        '<!DOCTYPE\nhtml><html><body>Proxy error</body></html>',
        '<article><h2>Recovered from newline doctype</h2></article>',
    ])

    assert.equal(result.fetchCount, 2)
    assert.equal(result.loadedEventCount, 1)
    assert.match(result.html, /Recovered from newline doctype/)
    assert.doesNotMatch(result.html, /Proxy error/)
})

test('leading head and body document roots retry before applying a fragment', async () => {
    const result = await runScenario([
        '<head><title>Proxy error</title></head><body>Error</body>',
        '<article><h2>Recovered from document roots</h2></article>',
    ])

    assert.equal(result.fetchCount, 2)
    assert.equal(result.loadedEventCount, 1)
    assert.match(result.html, /Recovered from document roots/)
    assert.doesNotMatch(result.html, /Proxy error/)
})

test('invalid cached HTML is discarded and cannot block a valid network fragment', async () => {
    const result = await runScenario(
        ['<article><h2>Fresh proof</h2></article>'],
        {
            cachedHtml:
                '\uFEFF <!-- cached proxy --> <?xml version="1.0"?> <html><body>Error page</body></html>',
        },
    )

    assert.equal(result.fetchCount, 1)
    assert.equal(result.loaded, 'true')
    assert.equal(result.loadedEventCount, 1)
    assert.match(result.html, /Fresh proof/)
    assert.doesNotMatch(result.html, /Error page/)
    assert.equal(result.storedHtml, '<article><h2>Fresh proof</h2></article>')
})

test('a multiple-space doctype is discarded from cache before network recovery', async () => {
    const result = await runScenario(
        ['<article><h2>Fresh after cached document</h2></article>'],
        {
            cachedHtml:
                '<!DOCTYPE   html><html><body>Cached proxy error</body></html>',
        },
    )

    assert.equal(result.fetchCount, 1)
    assert.equal(result.loadedEventCount, 1)
    assert.match(result.html, /Fresh after cached document/)
    assert.doesNotMatch(result.html, /Cached proxy error/)
    assert.equal(
        result.storedHtml,
        '<article><h2>Fresh after cached document</h2></article>',
    )
})

test('expired cached HTML is discarded and cannot block a valid network fragment', async () => {
    const result = await runScenario(
        ['<article><h2>Current proof</h2></article>'],
        {
            cachedHtml: '<article><h2>Expired proof</h2></article>',
            cachedStoredAt: Date.now() - 31 * 60 * 1000,
        },
    )

    assert.equal(result.fetchCount, 1)
    assert.match(result.html, /Current proof/)
    assert.doesNotMatch(result.html, /Expired proof/)
})

test('one intent removes all sibling listeners, starts one request, and creates no observer', async () => {
    const result = await runScenario(
        ['<article><h2>Intent proof</h2></article>'],
        {
            autoRunIdle: false,
            intentEvent: 'wheel',
            strategy: 'scroll',
        },
    )

    assert.equal(result.fetchCount, 1)
    assert.equal(result.loadedEventCount, 1)
    assert.equal(result.observerCount, 0)
    assert.equal(result.listenerCounts.scroll, 1)
    assert.equal(result.listenerCounts.wheel, 1)
    assert.equal(result.listenerCounts.touchmove, 1)
    assert.equal(result.listenerRemovalCounts.scroll, 1)
    assert.equal(result.listenerRemovalCounts.wheel, 1)
    assert.equal(result.listenerRemovalCounts.touchmove, 1)
    assert.equal(result.activeListenerCounts.scroll, 0)
    assert.equal(result.activeListenerCounts.wheel, 0)
    assert.equal(result.activeListenerCounts.touchmove, 0)
})

test('scroll fragments load on idle without requiring scroll or pointer input', async () => {
    const result = await runScenario(
        ['<article><h2>Assistive technology proof</h2></article>'],
        { strategy: 'scroll' },
    )

    assert.equal(result.fetchCount, 1)
    assert.equal(result.loadedEventCount, 1)
    assert.equal(result.observerCount, 0)
    assert.match(result.html, /Assistive technology proof/)
})

test('idle-only fragments create no observer', async () => {
    const result = await runScenario(
        ['<article><h2>Idle proof</h2></article>'],
        { strategy: 'idle' },
    )

    assert.equal(result.fetchCount, 1)
    assert.equal(result.loadedEventCount, 1)
    assert.equal(result.observerCount, 0)
})

test('reinitialization releases detached intent listeners and observer targets', async () => {
    const result = await page.evaluate(() => {
        const testState = window.__deferredTest
        const intentFragment = document.querySelector(
            '[data-deferred-fragment]',
        )
        const intersectionFragment = intentFragment.cloneNode(true)

        intentFragment.dataset.deferredFragmentStrategy = 'scroll'
        intersectionFragment.dataset.deferredFragmentKey = 'detached-proof'
        intersectionFragment.dataset.deferredFragmentUrl =
            '/_capell/fragments/detached-proof'
        intentFragment.after(intersectionFragment)
        testState.autoRunIdle = false

        testState.initialize()
        intentFragment.remove()
        intersectionFragment.remove()
        testState.initialize()

        return {
            activeListenerCounts: Object.fromEntries(
                Array.from(testState.activeListeners, ([type, listeners]) => [
                    type,
                    listeners.size,
                ]),
            ),
            listenerRemovalCounts: Object.fromEntries(
                testState.listenerRemovalCounts,
            ),
            observerDisconnectCount: testState.observers.reduce(
                (count, observer) => count + observer.disconnectCount,
                0,
            ),
            observerTargetCount: testState.observers.reduce(
                (count, observer) => count + observer.targets.size,
                0,
            ),
        }
    })

    assert.equal(result.listenerRemovalCounts.scroll, 1)
    assert.equal(result.listenerRemovalCounts.wheel, 1)
    assert.equal(result.listenerRemovalCounts.touchmove, 1)
    assert.equal(result.activeListenerCounts.scroll, 0)
    assert.equal(result.activeListenerCounts.wheel, 0)
    assert.equal(result.activeListenerCounts.touchmove, 0)
    assert.equal(result.observerDisconnectCount, 1)
    assert.equal(result.observerTargetCount, 0)
})

test('a queued idle callback cannot load a fragment after it is detached', async () => {
    const result = await page.evaluate(async () => {
        const testState = window.__deferredTest
        const fragment = document.querySelector('[data-deferred-fragment]')

        fragment.dataset.deferredFragmentStrategy = 'idle'
        testState.autoRunIdle = false
        testState.responses.push('<article><h2>Stale proof</h2></article>')
        testState.initialize()
        fragment.remove()
        testState.idleCallbacks.shift()?.()
        await new Promise((resolve) => setTimeout(resolve, 5))

        return {
            fetchCount: testState.fetchCount,
            html: fragment.innerHTML,
            initializeCount: testState.initializeCount,
            loaded: fragment.dataset.deferredFragmentLoaded ?? null,
            loadedEventCount: testState.loadedEventCount,
            storedCount: testState.stored.size,
        }
    })

    assert.equal(result.fetchCount, 0)
    assert.match(result.html, /Loading/)
    assert.equal(result.initializeCount, 0)
    assert.equal(result.loaded, null)
    assert.equal(result.loadedEventCount, 0)
    assert.equal(result.storedCount, 0)
})

test('an in-flight response cannot cache or apply after its fragment is detached', async () => {
    const result = await page.evaluate(async () => {
        const testState = window.__deferredTest
        const fragment = document.querySelector('[data-deferred-fragment]')

        testState.responses.push('__PENDING__')
        testState.initialize()
        testState.observers[0].intersect(fragment)

        for (
            let attempt = 0;
            attempt < 100 && testState.pendingResponses.length === 0;
            attempt += 1
        ) {
            await new Promise((resolve) => setTimeout(resolve, 1))
        }

        fragment.remove()
        testState.pendingResponses
            .shift()
            ?.resolve('<article><h2>Stale proof</h2></article>')
        await new Promise((resolve) => setTimeout(resolve, 5))

        return {
            fetchCount: testState.fetchCount,
            html: fragment.innerHTML,
            initializeCount: testState.initializeCount,
            loaded: fragment.dataset.deferredFragmentLoaded ?? null,
            loadedEventCount: testState.loadedEventCount,
            storedCount: testState.stored.size,
        }
    })

    assert.equal(result.fetchCount, 1)
    assert.match(result.html, /Loading/)
    assert.equal(result.initializeCount, 0)
    assert.equal(result.loaded, null)
    assert.equal(result.loadedEventCount, 0)
    assert.equal(result.storedCount, 0)
})

test('an in-flight failure cannot retry after its fragment is detached', async () => {
    const result = await page.evaluate(async () => {
        const testState = window.__deferredTest
        const fragment = document.querySelector('[data-deferred-fragment]')

        testState.responses.push('__PENDING__')
        testState.initialize()
        testState.observers[0].intersect(fragment)

        for (
            let attempt = 0;
            attempt < 100 && testState.pendingResponses.length === 0;
            attempt += 1
        ) {
            await new Promise((resolve) => setTimeout(resolve, 1))
        }

        fragment.remove()
        testState.pendingResponses
            .shift()
            ?.reject(new Error('Detached network failure'))
        await new Promise((resolve) => setTimeout(resolve, 10))

        return {
            fetchCount: testState.fetchCount,
            html: fragment.innerHTML,
            initializeCount: testState.initializeCount,
            loadedEventCount: testState.loadedEventCount,
            state: fragment.dataset.deferredFragmentState ?? null,
            storedCount: testState.stored.size,
        }
    })

    assert.equal(result.fetchCount, 1)
    assert.match(result.html, /Loading/)
    assert.equal(result.initializeCount, 0)
    assert.equal(result.loadedEventCount, 0)
    assert.equal(result.state, 'loading')
    assert.equal(result.storedCount, 0)
})

test('a second invalid response leaves an explicit collapsed non-busy error state', async () => {
    const result = await runScenario([
        '<html><body>Not a fragment</body></html>',
        '',
    ])

    assert.equal(result.fetchCount, 2)
    assert.equal(result.loaded, null)
    assert.equal(result.loadedEventCount, 0)
    assert.equal(result.initializeCount, 0)
    assert.equal(result.state, 'error')
    assert.equal(result.ariaBusy, null)
    assert.equal(result.html, '')
    assert.equal(result.minHeight, '')
    assert.equal(result.hidden, true)
})

test('a fragment failure promotes its inert fallback content', async () => {
    const result = await page.evaluate(async () => {
        const testState = window.__deferredTest
        const fragment = document.querySelector('[data-deferred-fragment]')
        const fallback = document.createElement('template')

        fallback.dataset.fragmentFallback = ''
        fallback.innerHTML =
            '<fragment data-fragment-fallback-content>Fallback links</fragment>'
        fragment.append(fallback)
        testState.responses.push(new Error('Fallback unavailable'))
        testState.initialize()
        testState.observers[0].intersect(fragment)

        for (
            let attempt = 0;
            attempt < 100 && fragment.dataset.deferredFragmentState !== 'error';
            attempt += 1
        ) {
            await new Promise((resolve) => setTimeout(resolve, 2))
        }

        return {
            fallback: fragment.dataset.deferredFragmentFallback ?? null,
            hidden: fragment.hidden,
            html: fragment.innerHTML,
            initializeCount: testState.initializeCount,
            minHeight: fragment.style.minHeight,
            state: fragment.dataset.deferredFragmentState ?? null,
        }
    })

    assert.equal(result.state, 'error')
    assert.equal(result.fallback, 'true')
    assert.equal(result.hidden, false)
    assert.equal(result.initializeCount, 1)
    assert.equal(result.minHeight, '')
    assert.match(result.html, /data-fragment-fallback-content/)
    assert.match(result.html, /Fallback links/)
})

test.afterEach(async () => {
    await page?.close()
})

for (const cacheControl of [
    'private, max-age=60',
    'public, no-store',
    'public, no-cache',
    null,
    'public',
    'public, max-age=0',
]) {
    test(`does not store structured fragments with disallowed policy ${cacheControl}`, async () => {
        const result = await runScenario([
            { html: '<p>Private content</p>', cacheable: true, cacheControl },
        ])
        assert.equal(result.loaded, 'true')
        assert.equal(result.storedHtml, undefined)
    })
}

test('plain HTML strings remain renderable but are never stored', async () => {
    const result = await page.evaluate(async (url) => {
        const { createDeferredFragments } = await import(url)
        const fragment = document.querySelector('[data-deferred-fragment]')
        fragment.dataset.deferredFragmentStrategy = 'idle'
        createDeferredFragments({
            fetchHtmlFragment: async () => '<p>Unclassified content</p>',
            onIdle: (callback) => callback(),
        })()
        for (
            let attempt = 0;
            attempt < 100 && fragment.dataset.deferredFragmentLoaded !== 'true';
            attempt += 1
        ) {
            await new Promise((resolve) => setTimeout(resolve, 2))
        }
        return {
            html: fragment.innerHTML,
            stored: [...window.__deferredTest.stored.values()],
        }
    }, moduleUrl)
    assert.match(result.html, /Unclassified content/)
    assert.deepEqual(result.stored, [])
})

test('discards legacy entries and metadata claiming a private response before restoration', async () => {
    await page.evaluate(() => {
        const stored = window.__deferredTest.stored
        stored.set(
            'capell:deferred-fragment:v1:homepage-proof',
            JSON.stringify({
                html: '<p>Legacy private content</p>',
                storedAt: Date.now(),
            }),
        )
        stored.set(
            'capell:deferred-fragment:v2:homepage-proof',
            JSON.stringify({
                html: '<p>Private response</p>',
                storedAt: Date.now(),
                cacheable: true,
                cacheControl: 'private, max-age=1800',
            }),
        )
    })
    const result = await runScenario(['<p>Fresh guest content</p>'])
    assert.equal(result.fetchCount, 1)
    assert.match(result.html, /Fresh guest content/)
    assert.equal(
        await page.evaluate(() =>
            window.__deferredTest.stored.has(
                'capell:deferred-fragment:v1:homepage-proof',
            ),
        ),
        false,
    )
})

test('expires restored public responses at their server max-age', async () => {
    await page.evaluate(() => {
        window.__deferredTest.stored.set(
            'capell:deferred-fragment:v2:homepage-proof',
            JSON.stringify({
                html: '<p>Expired short lifetime</p>',
                storedAt: Date.now() - 2000,
                cacheable: true,
                cacheControl: 'public, max-age=1',
            }),
        )
    })
    const result = await runScenario(['<p>Fresh short lifetime</p>'])
    assert.equal(result.fetchCount, 1)
    assert.match(result.html, /Fresh short lifetime/)
})
