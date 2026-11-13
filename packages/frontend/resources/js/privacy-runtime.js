// Capell's default consent runtime: defines window.CapellPrivacy when no consent manager
// has. Loaded from the page head as a same-origin script, never inline, so cached pages
// carry no per-request CSP nonce. It records nothing and sets no cookie: each category
// resolves to the site's configured default, or to a choose() call for the rest of the
// page, and a Global Privacy Control or Do Not Track signal refuses the opt-out categories.
// A consent manager (such as Privacy Center) renders its runtime first, and this one then
// leaves it in place. The stable API is allows, consent, choose and open.
// Either way, a gated frame shows a short notice in its place until its purpose is granted.
;(() => {
    // A page that includes the runtime twice (a cached fragment, a second render hook)
    // runs it once, so frames never get a second notice.
    const installed = Symbol.for('capell.privacy-runtime')
    if (window[installed]) return
    Object.defineProperty(window, installed, { value: true })

    const configElement = document.getElementById(
        'capell-privacy-runtime-config',
    )
    let config = {}
    try {
        config = JSON.parse(configElement?.textContent || '{}') || {}
    } catch {
        config = {}
    }

    const strings = (value) =>
        Array.isArray(value)
            ? value.filter((item) => typeof item === 'string')
            : []
    const categories = strings(config.categories)
    const purposes =
        config.purposes && typeof config.purposes === 'object'
            ? config.purposes
            : {}
    const defaults =
        config.defaults && typeof config.defaults === 'object'
            ? config.defaults
            : {}
    const optOutCategories = strings(config.optOutCategories)

    // A consent manager defined window.CapellPrivacy first: keep it, and offer its
    // choices panel from the frame notices.
    if (window.CapellPrivacy) {
        const syncManagedFrames = framePlaceholders(window.CapellPrivacy, true)
        const sync = (event) =>
            syncManagedFrames(
                event?.target instanceof Element ? event.target : document,
            )
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => sync(), {
                once: true,
            })
        } else {
            sync()
        }
        document.addEventListener('capell:content-ready', sync)
        document.addEventListener('capell:privacy-consent-changed', () =>
            sync(),
        )
        return
    }

    const globalPrivacyControl = navigator.globalPrivacyControl === true
    const doNotTrack = navigator.doNotTrack === '1' || window.doNotTrack === '1'

    // A choice made through choose() lasts for this page only: nothing is stored.
    let choice = null

    // true: granted. false: refused. null: not decided. Send analytics or marketing
    // signals only for true.
    const allows = (name) => {
        if (typeof name !== 'string') return null
        const category = categories.includes(name)
            ? name
            : Object.hasOwn(purposes, name)
              ? purposes[name]
              : null
        if (typeof category !== 'string' || !categories.includes(category))
            return null
        if (category === 'essential') return true
        if (
            (globalPrivacyControl || doNotTrack) &&
            optOutCategories.includes(category)
        )
            return false
        if (choice) return choice.granted.includes(category)
        const decision = Object.hasOwn(defaults, category)
            ? defaults[category]
            : null
        return decision === true || decision === false ? decision : null
    }

    // Scripts and frames marked with data-capell-consent load only once their purpose is granted.
    const activate = (root = document) => {
        root.querySelectorAll(
            'script[type="text/plain"][data-capell-consent]',
        ).forEach((placeholder) => {
            if (allows(placeholder.dataset.capellConsent) !== true) return
            const script = document.createElement('script')
            for (const attribute of placeholder.attributes) {
                if (
                    attribute.name === 'type' ||
                    attribute.name === 'data-capell-consent' ||
                    attribute.name === 'data-type'
                )
                    continue
                script.setAttribute(attribute.name, attribute.value)
            }
            if (placeholder.nonce) script.nonce = placeholder.nonce
            if (placeholder.dataset.type) script.type = placeholder.dataset.type
            script.text = placeholder.text
            placeholder.replaceWith(script)
        })
        root.querySelectorAll('iframe[data-capell-consent][data-src]').forEach(
            (frame) => {
                if (allows(frame.dataset.capellConsent) !== true) return
                if (!isWebAddress(frame.dataset.src)) return
                frame.src = frame.dataset.src
                frame.removeAttribute('data-src')
            },
        )
        syncFrames(root)
    }

    // Frames load only http and https addresses, never javascript: or data: URLs.
    const isWebAddress = (value) => {
        try {
            const { protocol } = new URL(value, document.baseURI)
            return protocol === 'https:' || protocol === 'http:'
        } catch {
            return false
        }
    }

    const consent = () =>
        choice
            ? {
                  id: choice.id,
                  version: choice.version,
                  granted: [...choice.granted],
              }
            : null

    // Records the visitor's granted categories for this page, loads what they now allow and
    // tells listeners, in the same shape a consent manager uses.
    const choose = (granted) => {
        if (!Array.isArray(granted)) return Promise.resolve(false)
        choice = {
            id: '',
            version: '',
            granted: categories.filter(
                (category) =>
                    category !== 'essential' && granted.includes(category),
            ),
        }
        activate()
        document.dispatchEvent(
            new CustomEvent('capell:privacy-consent-changed', {
                detail: consent(),
            }),
        )
        return Promise.resolve(true)
    }

    window.CapellPrivacy = Object.freeze({
        allows,
        consent,
        choose,
        // No consent manager is installed, so there is no choices panel to open.
        open: () => {},
    })

    // No choices panel to open, so the notice explains the wait without a button.
    const syncFrames = framePlaceholders(window.CapellPrivacy, false)

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => activate(), {
            once: true,
        })
    } else {
        activate()
    }
    document.addEventListener('capell:content-ready', (event) =>
        activate(event.target instanceof Element ? event.target : document),
    )

    // Puts a notice before each frame still waiting for consent and hides the empty frame;
    // once the frame has a src, the notice goes and the frame shows. Returns the sync step.
    function framePlaceholders(privacy, canOpen) {
        const text =
            config.framePlaceholder &&
            typeof config.framePlaceholder === 'object'
                ? config.framePlaceholder
                : {}
        const message = typeof text.message === 'string' ? text.message : ''
        const manage = typeof text.manage === 'string' ? text.manage : ''
        const notices = new Map()

        const reveal = (frame) => {
            const notice = notices.get(frame)
            if (!notice) return
            notice.remove()
            notices.delete(frame)
            frame.hidden = false
        }

        const sync = (root = document) => {
            for (const frame of [...notices.keys()]) {
                if (frame.getAttribute('src')) reveal(frame)
            }
            if (message === '') return
            root.querySelectorAll(
                'iframe[data-capell-consent][data-src]',
            ).forEach((frame) => {
                if (notices.has(frame) || frame.getAttribute('src')) return
                const notice = document.createElement('div')
                notice.setAttribute('data-capell-consent-placeholder', '')
                notice.setAttribute('role', 'note')
                const paragraph = document.createElement('p')
                paragraph.textContent = message
                notice.append(paragraph)
                if (
                    canOpen &&
                    manage !== '' &&
                    typeof privacy.open === 'function'
                ) {
                    const button = document.createElement('button')
                    button.type = 'button'
                    button.textContent = manage
                    button.addEventListener('click', () => privacy.open())
                    notice.append(button)
                }
                frame.before(notice)
                frame.hidden = true
                notices.set(frame, notice)
                // A consent manager may load the frame on its own schedule.
                if (typeof MutationObserver === 'function') {
                    new MutationObserver((changes, observer) => {
                        if (!frame.getAttribute('src')) return
                        observer.disconnect()
                        reveal(frame)
                    }).observe(frame, { attributeFilter: ['src'] })
                }
            })
        }

        return sync
    }
})()
