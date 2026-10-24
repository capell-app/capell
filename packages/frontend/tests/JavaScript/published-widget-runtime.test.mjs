import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import vm from 'node:vm'

// Exercise the artefact consumers publish, including its bundled dependencies.
for (const artifact of ['source', 'published']) {
    test(`the ${artifact} runtime rejects a full document returned as a deferred fragment`, async () => {
        const build = new URL('../../publishes/build/', import.meta.url)
        const manifest = JSON.parse(
            readFileSync(new URL('manifest.json', build)),
        )
        const observers = []
        const placeholder = '<span>Loading</span>'
        const documentResponse =
            '<!doctype html><html><body>Error page</body></html>'
        class Element {
            constructor() {
                this.dataset = {
                    deferredFragment: '',
                    deferredFragmentUrl: '/_capell/fragments/example',
                    deferredFragmentKey: 'example',
                }
                this.innerHTML = placeholder
                this.isConnected = true
                this.style = { removeProperty() {} }
                this.classList = { contains: () => false, remove() {} }
                this.relList = { supports: () => false }
            }
            matches() {
                return false
            }
            querySelectorAll() {
                return []
            }
            getAttribute() {
                return null
            }
            setAttribute() {}
            removeAttribute() {}
            dispatchEvent() {}
            addEventListener() {}
        }
        const fragment = new Element()
        const document = {
            readyState: 'loading',
            baseURI: 'https://capell.test/',
            addEventListener() {},
            querySelector: () => null,
            querySelectorAll: (selector) =>
                selector === '[data-deferred-fragment]' ? [fragment] : [],
            createElement: () => new Element(),
            body: new Element(),
        }
        const context = {
            document,
            Element,
            HTMLElement: Element,
            MutationObserver: class {
                observe() {}
                disconnect() {}
            },
            IntersectionObserver: class {
                constructor(callback) {
                    this.callback = callback
                    observers.push(this)
                }
                observe() {}
                disconnect() {}
                unobserve() {}
            },
            CustomEvent: class {},
            AbortController,
            URL,
            setTimeout,
            clearTimeout,
            queueMicrotask,
            console,
            fetch: async () => ({
                ok: true,
                headers: { get: () => 'no-store' },
                text: async () => documentResponse,
            }),
            sessionStorage: {
                getItem: () => null,
                setItem() {},
                removeItem() {},
            },
            location: {
                origin: 'https://capell.test',
                href: 'https://capell.test/',
            },
            addEventListener() {},
        }
        context.window = context
        const sandbox = vm.createContext(context)
        const modules = new Map()
        const moduleFor = (url) => {
            if (!modules.has(url.href)) {
                modules.set(
                    url.href,
                    new vm.SourceTextModule(readFileSync(url, 'utf8'), {
                        identifier: url.href,
                        context: sandbox,
                    }),
                )
            }
            return modules.get(url.href)
        }
        const entry = moduleFor(
            artifact === 'published'
                ? new URL(
                      manifest['resources/js/capell-frontend.js'].file,
                      build,
                  )
                : new URL(
                      '../../resources/js/widget-runtime.js',
                      import.meta.url,
                  ),
        )
        await entry.link((specifier, parent) =>
            moduleFor(new URL(specifier, parent.identifier)),
        )
        await entry.evaluate()
        context.CapellWidgetRuntime.init()
        for (const observer of observers) {
            observer.callback(
                [{ target: fragment, isIntersecting: true }],
                observer,
            )
        }
        await new Promise((resolve) => setImmediate(resolve))
        assert.equal(fragment.innerHTML, placeholder)
    })
}
