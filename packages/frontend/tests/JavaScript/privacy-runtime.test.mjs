import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import vm from 'node:vm'

const source = readFileSync(
    new URL('../../resources/js/privacy-runtime.js', import.meta.url),
    'utf8',
)

const siteConfig = {
    categories: [
        'essential',
        'functional',
        'preferences',
        'analytics',
        'marketing',
    ],
    purposes: { youtube: 'marketing', openstreetmap: 'functional' },
    defaults: {
        essential: true,
        functional: true,
        preferences: false,
        analytics: true,
        marketing: null,
    },
    optOutCategories: ['analytics', 'marketing'],
}

class FakeElement {
    constructor(tagName, attributes = {}, text = '') {
        this.tagName = tagName
        this.attributeMap = new Map(Object.entries(attributes))
        this.text = text
        this.nonce = ''
        this.parent = null
    }

    get attributes() {
        return [...this.attributeMap].map(([name, value]) => ({ name, value }))
    }

    get dataset() {
        const dataset = {}
        for (const [name, value] of this.attributeMap) {
            if (!name.startsWith('data-')) continue
            const key = name
                .slice(5)
                .replace(/-([a-z])/g, (_, letter) => letter.toUpperCase())
            dataset[key] = value
        }
        return dataset
    }

    get type() {
        return this.attributeMap.get('type') ?? ''
    }

    set type(value) {
        this.attributeMap.set('type', value)
    }

    get src() {
        return this.attributeMap.get('src') ?? ''
    }

    set src(value) {
        this.attributeMap.set('src', value)
    }

    getAttribute(name) {
        return this.attributeMap.get(name) ?? null
    }

    setAttribute(name, value) {
        this.attributeMap.set(name, String(value))
    }

    removeAttribute(name) {
        this.attributeMap.delete(name)
    }

    replaceWith(element) {
        const index = this.parent.children.indexOf(this)
        this.parent.children.splice(index, 1, element)
        element.parent = this.parent
    }
}

function fakePage({
    config = siteConfig,
    navigator = {},
    existing,
    children = [],
    readyState = 'complete',
} = {}) {
    const listeners = new Map()
    const cookieWrites = []
    const root = { children: [] }
    const configElement = {
        textContent:
            typeof config === 'string' ? config : JSON.stringify(config),
    }
    for (const child of children) {
        child.parent = root
        root.children.push(child)
    }

    const matches = (element, selector) => {
        if (selector.startsWith('script')) {
            return (
                element.tagName === 'script' &&
                element.getAttribute('type') === 'text/plain' &&
                element.getAttribute('data-capell-consent') !== null
            )
        }
        return (
            element.tagName === 'iframe' &&
            element.getAttribute('data-capell-consent') !== null &&
            element.getAttribute('data-src') !== null
        )
    }

    const events = []
    const document = {
        readyState,
        baseURI: 'https://example.test/about',
        dispatchEvent: (event) => events.push(event),
        getElementById: (id) =>
            id === 'capell-privacy-runtime-config' ? configElement : null,
        querySelectorAll: (selector) =>
            root.children.filter((element) => matches(element, selector)),
        createElement: (tagName) => new FakeElement(tagName),
        addEventListener: (type, listener) => listeners.set(type, listener),
        get cookie() {
            return ''
        },
        set cookie(value) {
            cookieWrites.push(value)
        },
    }
    const window = existing ? { CapellPrivacy: existing } : {}
    const context = {
        window,
        document,
        navigator,
        Element: FakeElement,
        URL,
        CustomEvent: class CustomEvent {
            constructor(type, init) {
                this.type = type
                this.detail = init?.detail
            }
        },
    }

    vm.runInNewContext(source, context)

    return { window, root, listeners, cookieWrites, events }
}

test('grants only what the site allows and leaves marketing undecided', () => {
    const { window } = fakePage()
    const privacy = window.CapellPrivacy

    assert.equal(privacy.allows('essential'), true)
    assert.equal(privacy.allows('functional'), true)
    assert.equal(privacy.allows('preferences'), false)
    assert.equal(privacy.allows('analytics'), true)
    assert.equal(privacy.allows('marketing'), null)
    assert.equal(privacy.allows('youtube'), null)
    assert.equal(privacy.allows('openstreetmap'), true)
    assert.equal(privacy.consent(), null)
})

test('exposes only the API a consent manager also provides', () => {
    const privacy = fakePage().window.CapellPrivacy

    assert.deepEqual(Object.keys(privacy).sort(), [
        'allows',
        'choose',
        'consent',
        'open',
    ])
})

test('refuses the opt-out categories under Global Privacy Control or Do Not Track', () => {
    const allGranted = {
        ...siteConfig,
        defaults: { ...siteConfig.defaults, marketing: true },
    }

    for (const navigator of [
        { globalPrivacyControl: true },
        { doNotTrack: '1' },
    ]) {
        const privacy = fakePage({ config: allGranted, navigator }).window
            .CapellPrivacy

        assert.equal(privacy.allows('analytics'), false)
        assert.equal(privacy.allows('marketing'), false)
        assert.equal(privacy.allows('youtube'), false)
        assert.equal(privacy.allows('functional'), true)
        assert.equal(privacy.allows('essential'), true)
    }
})

test('leaves unknown and inherited names undecided', () => {
    const privacy = fakePage().window.CapellPrivacy

    for (const name of [
        'vimeo',
        'constructor',
        '__proto__',
        'toString',
        undefined,
        42,
    ]) {
        assert.equal(privacy.allows(name), null, String(name))
    }
})

test('keeps a consent manager runtime that is already defined', () => {
    const manager = { allows: () => false }
    const { window } = fakePage({ existing: manager })

    assert.equal(window.CapellPrivacy, manager)
})

test('survives a missing or malformed configuration by deciding nothing', () => {
    for (const config of ['', '{not json', 'null', '[]']) {
        const privacy = fakePage({ config }).window.CapellPrivacy

        assert.equal(privacy.allows('essential'), null)
        assert.equal(privacy.allows('analytics'), null)
    }
})

test('cannot be changed by page scripts', () => {
    const { window } = fakePage()
    const privacy = window.CapellPrivacy

    assert.equal(Object.isFrozen(privacy), true)
    assert.throws(() => {
        privacy.allows = () => true
    }, TypeError)
})

test('loads gated scripts and frames only for granted purposes', () => {
    const analytics = new FakeElement(
        'script',
        {
            type: 'text/plain',
            'data-capell-consent': 'analytics',
            'data-type': 'module',
            src: '/analytics.js',
        },
        '',
    )
    analytics.nonce = 'cached-nonce'
    const marketing = new FakeElement(
        'script',
        { type: 'text/plain', 'data-capell-consent': 'marketing' },
        'track()',
    )
    const map = new FakeElement('iframe', {
        'data-capell-consent': 'openstreetmap',
        'data-src': 'https://www.openstreetmap.org/export/embed.html',
    })
    const video = new FakeElement('iframe', {
        'data-capell-consent': 'youtube',
        'data-src': 'https://www.youtube-nocookie.com/embed/x',
    })

    const { root, cookieWrites } = fakePage({
        children: [analytics, marketing, map, video],
    })
    const [loadedAnalytics, keptMarketing] = root.children

    assert.notEqual(loadedAnalytics, analytics)
    assert.equal(loadedAnalytics.type, 'module')
    assert.equal(loadedAnalytics.getAttribute('src'), '/analytics.js')
    assert.equal(loadedAnalytics.getAttribute('data-capell-consent'), null)
    assert.equal(loadedAnalytics.getAttribute('data-type'), null)
    assert.equal(loadedAnalytics.nonce, 'cached-nonce')
    assert.equal(keptMarketing, marketing)
    assert.equal(keptMarketing.type, 'text/plain')
    assert.equal(map.src, 'https://www.openstreetmap.org/export/embed.html')
    assert.equal(map.getAttribute('data-src'), null)
    assert.equal(video.src, '')
    assert.equal(
        video.getAttribute('data-src'),
        'https://www.youtube-nocookie.com/embed/x',
    )
    assert.deepEqual(cookieWrites, [])
})

test('waits for the document before activating, then activates new content', () => {
    const frame = new FakeElement('iframe', {
        'data-capell-consent': 'functional',
        'data-src': '/embed',
    })
    const { listeners } = fakePage({
        readyState: 'loading',
        children: [frame],
    })

    assert.equal(frame.src, '')
    listeners.get('DOMContentLoaded')()
    assert.equal(frame.src, '/embed')
    assert.equal(typeof listeners.get('capell:content-ready'), 'function')
})

test('applies a choice for the page, announces it and still honours opt-out signals', async () => {
    const frame = new FakeElement('iframe', {
        'data-capell-consent': 'youtube',
        'data-src': 'https://www.youtube-nocookie.com/embed/x',
    })
    const { window, events, cookieWrites } = fakePage({ children: [frame] })
    const privacy = window.CapellPrivacy

    assert.equal(frame.src, '')
    assert.equal(
        await privacy.choose(['marketing', 'essential', 'unknown']),
        true,
    )
    assert.equal(privacy.allows('marketing'), true)
    assert.equal(privacy.allows('youtube'), true)
    assert.equal(privacy.allows('analytics'), false)
    assert.equal(privacy.allows('essential'), true)
    assert.deepEqual(
        { ...privacy.consent(), granted: [...privacy.consent().granted] },
        {
            id: '',
            version: '',
            granted: ['marketing'],
        },
    )
    assert.equal(frame.src, 'https://www.youtube-nocookie.com/embed/x')
    assert.equal(events.length, 1)
    assert.equal(events[0].type, 'capell:privacy-consent-changed')
    assert.deepEqual([...events[0].detail.granted], ['marketing'])
    assert.equal(await privacy.choose('marketing'), false)
    assert.equal(events.length, 1)
    assert.deepEqual(cookieWrites, [])

    const optedOut = fakePage({ navigator: { globalPrivacyControl: true } })
        .window.CapellPrivacy
    await optedOut.choose(['marketing'])
    assert.equal(optedOut.allows('marketing'), false)
})

test('never loads a frame from a non-web address', () => {
    const frames = [
        'javascript:alert(1)',
        'data:text/html,x',
        ' JavaScript:alert(1)',
    ].map(
        (src) =>
            new FakeElement('iframe', {
                'data-capell-consent': 'functional',
                'data-src': src,
            }),
    )
    const relative = new FakeElement('iframe', {
        'data-capell-consent': 'functional',
        'data-src': '/embed/map',
    })
    fakePage({ children: [...frames, relative] })

    for (const frame of frames) assert.equal(frame.src, '')
    assert.equal(relative.src, '/embed/map')
})
