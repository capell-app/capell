// Capell's default consent runtime: defines window.CapellPrivacy when no consent manager
// has. Loaded from the page head as a same-origin script, never inline, so cached pages
// carry no per-request CSP nonce. It records nothing and sets no cookie: each category
// resolves to the site's configured default, and a Global Privacy Control or Do Not Track
// signal refuses the opt-out categories. A consent manager (such as Privacy Center) renders
// its runtime first, and this one then leaves it in place.
;(() => {
    if (window.CapellPrivacy) return

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
    const categories = Object.freeze(strings(config.categories))
    const purposes =
        config.purposes && typeof config.purposes === 'object'
            ? config.purposes
            : {}
    const defaults =
        config.defaults && typeof config.defaults === 'object'
            ? config.defaults
            : {}
    const optOutCategories = strings(config.optOutCategories)

    const globalPrivacyControl = navigator.globalPrivacyControl === true
    const doNotTrack = navigator.doNotTrack === '1' || window.doNotTrack === '1'

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
                frame.src = frame.dataset.src
                frame.removeAttribute('data-src')
            },
        )
    }

    window.CapellPrivacy = Object.freeze({
        allows,
        categories,
        globalPrivacyControl,
        doNotTrack,
        // No consent manager is installed, so there is no saved choice to read or change.
        consent: () => null,
        choose: () => Promise.resolve(false),
        open: () => {},
    })

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
})()
