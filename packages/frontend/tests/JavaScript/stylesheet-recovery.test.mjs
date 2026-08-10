import assert from 'node:assert/strict'
import test from 'node:test'
import vm from 'node:vm'
import { readFile } from 'node:fs/promises'

const source = await readFile(
    new URL('../../resources/js/stylesheet-recovery.js', import.meta.url),
    'utf8',
)

test('loads the stable fallback and upgrades to the intended stylesheet', async () => {
    const document = fakeDocument()
    const primary = document.createPrimary('/build/assets/frontend-old.css')
    const timers = []
    const context = {
        CustomEvent: class CustomEvent {
            constructor(type, init) {
                this.type = type
                this.detail = init?.detail
            }
        },
        URL,
        document,
        window: {
            location: { href: 'https://capell.app/contact' },
            setTimeout: (callback) => timers.push(callback),
            addEventListener: () => {},
        },
    }

    vm.runInNewContext(source, context)
    document.emit('error', primary)

    const fallback = document.appended.find(
        (link) => link.dataset.capellStylesheetFallbackActive === 'true',
    )
    assert.equal(
        fallback.href,
        '/build/fallback/resources/css/capell/frontend.css',
    )
    fallback.emit('load')
    assert.equal(document.documentElement.dataset.frontendStyles, 'fallback')

    timers.shift()()
    const retry = document.appended.find(
        (link) => link.dataset.capellStylesheetRetry === 'true',
    )
    assert.equal(retry.matches('link[data-capell-stylesheet-recovery]'), false)
    retry.emit('load')

    assert.equal(retry.media, 'all')
    assert.equal(fallback.removed, true)
    assert.equal(document.documentElement.dataset.frontendStyles, 'loaded')
    assert.equal(document.events.at(-1).type, 'capell:stylesheet-recovered')
})

test('keeps the fallback active until every failed stylesheet has recovered', () => {
    const document = fakeDocument()
    const failed = document.createPrimary('/build/assets/frontend-missing.css')
    const healthy = document.createPrimary('/build/assets/theme-ready.css')
    const timers = []
    const context = {
        CustomEvent: class CustomEvent {
            constructor(type, init) {
                this.type = type
                this.detail = init?.detail
            }
        },
        URL,
        document,
        window: {
            location: { href: 'https://capell.app/contact' },
            setTimeout: (callback) => timers.push(callback),
            addEventListener: () => {},
        },
    }

    vm.runInNewContext(source, context)
    document.emit('error', failed)

    const fallback = document.appended.find(
        (link) => link.dataset.capellStylesheetFallbackActive === 'true',
    )
    fallback.emit('load')
    document.emit('load', healthy)

    assert.equal(fallback.removed, false)
    assert.equal(document.documentElement.dataset.frontendStyles, 'fallback')
    assert.equal(
        document.documentElement.dataset.frontendStylesLoaded,
        undefined,
    )

    timers.shift()()
    const retry = document.appended.find(
        (link) => link.dataset.capellStylesheetRetry === 'true',
    )
    retry.emit('load')

    assert.equal(fallback.removed, true)
    assert.equal(document.documentElement.dataset.frontendStyles, 'loaded')
    assert.equal(document.documentElement.dataset.frontendStylesLoaded, 'true')
})

test('ignores a fallback load event queued after recovery removed it', () => {
    const document = fakeDocument()
    const primary = document.createPrimary('/build/assets/frontend-old.css')
    const timers = []
    const context = {
        CustomEvent: class CustomEvent {
            constructor(type, init) {
                this.type = type
                this.detail = init?.detail
            }
        },
        URL,
        document,
        window: {
            location: { href: 'https://capell.app/contact' },
            setTimeout: (callback) => timers.push(callback),
            addEventListener: () => {},
        },
    }

    vm.runInNewContext(source, context)
    document.emit('error', primary)

    const fallback = document.appended.find(
        (link) => link.dataset.capellStylesheetFallbackActive === 'true',
    )
    timers.shift()()
    const retry = document.appended.find(
        (link) => link.dataset.capellStylesheetRetry === 'true',
    )
    retry.emit('load')
    fallback.emit('load')

    assert.equal(fallback.removed, true)
    assert.equal(document.documentElement.dataset.frontendStyles, 'loaded')
})

function fakeDocument() {
    const listeners = new Map()
    const appended = []
    const events = []
    const classes = new Set()

    const document = {
        appended,
        events,
        baseURI: 'https://capell.app/contact',
        documentElement: {
            dataset: {},
            classList: {
                add: (...names) => names.forEach((name) => classes.add(name)),
                remove: (...names) =>
                    names.forEach((name) => classes.delete(name)),
            },
        },
        head: {
            appendChild: (element) => {
                element.isConnected = true
                appended.push(element)
            },
        },
        addEventListener: (type, callback) => listeners.set(type, callback),
        dispatchEvent: (event) => events.push(event),
        querySelectorAll: (selector) =>
            selector.includes('data-capell-stylesheet-fallback-active')
                ? appended.filter(
                      (link) =>
                          link.dataset.capellStylesheetFallbackActive ===
                              'true' && !link.removed,
                  )
                : [],
        querySelector: (selector) =>
            selector.includes('data-capell-stylesheet-fallback-active')
                ? appended.find(
                      (link) =>
                          link.dataset.capellStylesheetFallbackActive ===
                              'true' && !link.removed,
                  )
                : null,
        createElement: () => linkElement(),
        createPrimary: (href) => {
            const link = linkElement()
            link.href = href
            link.dataset.capellStylesheetRecovery = ''
            link.dataset.capellStylesheetFallback =
                '/build/fallback/resources/css/capell/frontend.css'
            link.media = 'print'

            return link
        },
        emit: (type, target) => listeners.get(type)?.({ target }),
    }

    return document
}

function linkElement() {
    const listeners = new Map()

    const link = {
        dataset: {},
        href: '',
        media: '',
        rel: '',
        isConnected: false,
        removed: false,
        matches: (selector) =>
            selector.includes('data-capell-stylesheet-recovery') &&
            Object.hasOwn(link.dataset, 'capellStylesheetRecovery'),
        addEventListener: (type, callback) => listeners.set(type, callback),
        removeAttribute: (attribute) => {
            if (attribute === 'data-capell-stylesheet-recovery') {
                delete link.dataset.capellStylesheetRecovery
            }
            if (attribute === 'data-capell-stylesheet-fallback') {
                delete link.dataset.capellStylesheetFallback
            }
        },
        cloneNode() {
            const clone = linkElement()
            clone.href = this.href
            clone.media = this.media
            clone.rel = this.rel
            clone.dataset = { ...this.dataset }

            return clone
        },
        emit: (type) => listeners.get(type)?.(),
        remove() {
            this.isConnected = false
            this.removed = true
        },
    }

    return link
}
