const resourceStates =
    window.__capellWidgetResourceStates instanceof Map
        ? window.__capellWidgetResourceStates
        : new Map()

window.__capellWidgetResourceStates = resourceStates

const overlayStack = []
const interactionBindings = new Set()
const isolatedElements = new Map()
let preservedScrollY = 0

const parseJson = (element) => {
    try {
        return JSON.parse(element.textContent || '{}')
    } catch {
        return {}
    }
}

const assetsById = () => {
    const element = document.querySelector(
        'script[type="application/json"][data-capell-widget-assets]',
    )
    const parsed = element ? parseJson(element) : {}

    return parsed && typeof parsed === 'object' && !Array.isArray(parsed)
        ? parsed
        : {}
}

const settingsFor = (element) => {
    try {
        return JSON.parse(element.dataset.capellWidgetSettings || '{}')
    } catch {
        return {}
    }
}

const resourceIdsFor = (element) => {
    try {
        const ids = JSON.parse(element.dataset.capellWidgetResources || '[]')

        return Array.isArray(ids)
            ? ids.filter((id) => typeof id === 'string')
            : []
    } catch {
        return []
    }
}

const canonicalUrl = (url) => {
    try {
        const parsed = new URL(url, document.baseURI)

        if (
            !['http:', 'https:'].includes(parsed.protocol) ||
            parsed.origin !== window.location.origin ||
            parsed.username ||
            parsed.password
        ) {
            return null
        }

        return parsed.href
    } catch {
        return null
    }
}

const normalizedAsset = (asset) => {
    if (!asset || typeof asset !== 'object') {
        return null
    }

    if (!['css', 'js'].includes(asset.kind) || typeof asset.url !== 'string') {
        return null
    }

    const url = canonicalUrl(asset.url)

    return url
        ? {
              kind: asset.kind,
              url,
              defer: Boolean(asset.defer),
              async: Boolean(asset.async),
              module: asset.module !== false,
          }
        : null
}

const resourceKey = (asset) => `${asset.kind}:${asset.url}`

const readyState = () => ({
    status: 'ready',
    promise: Promise.resolve(),
    error: null,
})

const seedDocumentResources = () => {
    document
        .querySelectorAll('link[rel="stylesheet"][href], script[src]')
        .forEach((element) => {
            const isStylesheet = element.matches('link[rel="stylesheet"]')
            const url = canonicalUrl(
                isStylesheet
                    ? element.getAttribute('href')
                    : element.getAttribute('src'),
            )

            if (!url) {
                return
            }

            const key = `${isStylesheet ? 'css' : 'js'}:${url}`
            if (!resourceStates.has(key)) {
                resourceStates.set(key, readyState())
            }
        })
}

const createResourceElement = (asset) => {
    if (asset.kind === 'css') {
        const link = document.createElement('link')
        link.rel = 'stylesheet'
        link.href = asset.url

        return link
    }

    const script = document.createElement('script')
    script.src = asset.url
    if (asset.module) {
        script.type = 'module'
    }
    script.defer = asset.defer
    script.async = asset.async

    return script
}

const loadResource = (candidate, retry = false) => {
    const asset = normalizedAsset(candidate)
    if (!asset) {
        return Promise.reject(new Error('Unsupported widget resource'))
    }

    const key = resourceKey(asset)
    const existing = resourceStates.get(key)

    if (existing?.status === 'ready' || existing?.status === 'loading') {
        return existing.promise
    }

    if (existing?.status === 'failed' && !retry) {
        return Promise.reject(existing.error)
    }

    const element = createResourceElement(asset)
    const promise = new Promise((resolve, reject) => {
        element.addEventListener(
            'load',
            () => {
                resourceStates.set(key, readyState())
                resolve()
            },
            { once: true },
        )
        element.addEventListener(
            'error',
            () => {
                const error = new Error(`Widget resource failed: ${asset.url}`)
                element.remove()
                resourceStates.set(key, {
                    status: 'failed',
                    promise,
                    error,
                })
                reject(error)
            },
            { once: true },
        )
    })

    resourceStates.set(key, { status: 'loading', promise, error: null })
    ;(asset.kind === 'css' ? document.head : document.body).appendChild(element)

    return promise
}

const knownAssetsForIds = (resourceIds, allAssets = assetsById()) => {
    const knownIds = []
    const resources = []
    const seen = new Set()

    resourceIds.forEach((resourceId) => {
        const assets = allAssets[resourceId]
        if (!Array.isArray(assets)) {
            return
        }

        knownIds.push(resourceId)
        assets.forEach((candidate) => {
            const asset = normalizedAsset(candidate)
            if (!asset || seen.has(resourceKey(asset))) {
                return
            }

            seen.add(resourceKey(asset))
            resources.push(asset)
        })
    })

    return { knownIds, resources }
}

const dispatchResourceState = (element, status, resourceIds, failures = []) => {
    element.dispatchEvent(
        new CustomEvent(`capell:widget-assets-${status}`, {
            bubbles: true,
            detail: {
                resourceIds,
                failures: failures.map(
                    (failure) => failure.reason?.message || 'failed',
                ),
            },
        }),
    )
}

const loadKnownResourceIds = async (
    element,
    resourceIds,
    { retry = false, allAssets = assetsById() } = {},
) => {
    seedDocumentResources()
    const { knownIds, resources } = knownAssetsForIds(resourceIds, allAssets)
    const results = await Promise.allSettled(
        resources.map((asset) => loadResource(asset, retry)),
    )
    const failures = results.filter((result) => result.status === 'rejected')

    dispatchResourceState(
        element,
        failures.length === 0 ? 'ready' : 'failed',
        knownIds,
        failures,
    )

    return { knownIds, failures }
}

const connectionIsFast = () => {
    const connection =
        navigator.connection ||
        navigator.mozConnection ||
        navigator.webkitConnection

    if (!connection) {
        return true
    }

    if (
        connection.saveData ||
        ['slow-2g', '2g'].includes(connection.effectiveType)
    ) {
        return false
    }

    return !connection.downlink || connection.downlink >= 1.5
}

const settingsAllowActivation = (settings) => {
    if (
        ['fast_only', 'hide_on_save_data'].includes(
            settings.connection_requirement,
        ) &&
        !connectionIsFast()
    ) {
        return false
    }

    if (settings.device_visibility === 'mobile_only') {
        return window.matchMedia('(max-width: 767px)').matches
    }

    if (settings.device_visibility === 'desktop_only') {
        return window.matchMedia('(min-width: 768px)').matches
    }

    if (settings.device_visibility === 'custom_range') {
        const min = Number(settings.min_viewport_width || 0)
        const max = Number(settings.max_viewport_width || 4096)

        return window.matchMedia(
            `(min-width: ${min}px) and (max-width: ${max}px)`,
        ).matches
    }

    return true
}

const loadAssets = async (element, allAssets) => {
    if (element.dataset.capellWidgetRuntimeLoaded === 'true') {
        return
    }

    if (!settingsAllowActivation(settingsFor(element))) {
        return
    }

    element.dataset.capellWidgetRuntimeLoaded = 'true'
    await loadKnownResourceIds(element, resourceIdsFor(element), { allAssets })
}

const onVisible = (element, callback) => {
    if (!('IntersectionObserver' in window)) {
        callback()

        return
    }

    const observer = new IntersectionObserver((entries) => {
        if (!entries.some((entry) => entry.isIntersecting)) {
            return
        }

        observer.disconnect()
        callback()
    })

    observer.observe(element)
}

const onInteraction = (element, callback) => {
    element.addEventListener('click', callback, { once: true })
}

const onIdle = (callback) => {
    if ('requestIdleCallback' in window) {
        window.requestIdleCallback(callback, { timeout: 2000 })

        return
    }

    window.setTimeout(callback, 600)
}

const initWidgetRuntime = (root = document) => {
    const allAssets = assetsById()

    root.querySelectorAll('[data-capell-widget-runtime]').forEach((element) => {
        const settings = settingsFor(element)
        const load = () => loadAssets(element, allAssets).catch(() => {})

        if (settings.loading_strategy === 'visible') {
            onVisible(element, load)
        } else if (settings.loading_strategy === 'interaction') {
            onInteraction(element, load)
        } else if (settings.loading_strategy === 'idle') {
            onIdle(load)
        } else {
            load()
        }
    })
}

const loadFragment = async (element) => {
    if (element.dataset.deferredFragmentLoaded === 'true') {
        return
    }

    const url = element.dataset.deferredFragmentUrl
    if (!url) {
        return
    }

    element.dataset.deferredFragmentLoaded = 'true'
    const response = await fetch(url, {
        headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
    })

    if (!response.ok) {
        throw new Error('Deferred fragment failed to load')
    }

    element.innerHTML = await response.text()
    activateNestedRuntime(element)
}

const initFragments = (root = document) => {
    root.querySelectorAll('[data-deferred-fragment]').forEach((element) => {
        onVisible(element, () => loadFragment(element).catch(() => {}))
    })
}

const validInteractionPayload = (payload) =>
    payload &&
    typeof payload === 'object' &&
    payload.version === 2 &&
    payload.status === 'ok' &&
    typeof payload.html === 'string' &&
    Array.isArray(payload.resource_ids) &&
    payload.resource_ids.length <= 100 &&
    payload.resource_ids.every(
        (id) => typeof id === 'string' && id.length <= 128,
    )

const fetchInteractionPayload = async (trigger) => {
    const response = await fetch(trigger.href, {
        headers: {
            Accept: 'application/vnd.capell.widget.v2+json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    })

    if (!response.ok) {
        throw new Error('Interaction target failed to load')
    }

    const payload = await response.json()
    if (!validInteractionPayload(payload)) {
        throw new Error('Interaction target returned an invalid response')
    }

    const knownIds = payload.resource_ids.filter((id) =>
        Object.prototype.hasOwnProperty.call(assetsById(), id),
    )

    return { html: payload.html, resourceIds: [...new Set(knownIds)] }
}

const activateNestedRuntime = (element) => {
    initWidgetRuntime(element)
    initFragments(element)
    initInteractions(element)
}

const triggerLabel = (trigger) =>
    trigger.getAttribute('aria-label') ||
    trigger.textContent.trim() ||
    'Content'

const localized = (trigger, key, fallback) =>
    trigger.dataset[`capellInteraction${key}`] || fallback

const escapeHtml = (value) =>
    String(value).replace(
        /[&<>'"]/g,
        (character) =>
            ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                "'": '&#039;',
                '"': '&quot;',
            })[character],
    )

const focusableElements = (root) =>
    [
        ...root.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
        ),
    ].filter(
        (element) => !element.hidden && element.getClientRects().length > 0,
    )

const isolateBackground = () => {
    const top = overlayStack.at(-1)?.element

    Array.from(document.body.children).forEach((element) => {
        if (element === top) {
            element.inert = false
            element.removeAttribute('aria-hidden')

            return
        }

        if (!isolatedElements.has(element)) {
            isolatedElements.set(element, {
                inert: element.inert,
                ariaHidden: element.getAttribute('aria-hidden'),
            })
        }
        element.inert = true
        element.setAttribute('aria-hidden', 'true')
    })
}

const restoreBackground = () => {
    isolatedElements.forEach((state, element) => {
        if (!element.isConnected) {
            return
        }

        element.inert = state.inert
        if (state.ariaHidden === null) {
            element.removeAttribute('aria-hidden')
        } else {
            element.setAttribute('aria-hidden', state.ariaHidden)
        }
    })
    isolatedElements.clear()
}

const modalClassFor = (trigger) => {
    const allowedSizes = ['sm', 'md', 'lg', 'xl', 'screen']
    const requested = trigger.dataset.capellInteractionModalSize || 'lg'
    const size = allowedSizes.includes(requested) ? requested : 'lg'

    return `capell-interaction-dialog capell-interaction-dialog--${size}`
}

const closeOverlay = (entry) => {
    if (overlayStack.at(-1) !== entry) {
        return
    }

    entry.controller.abort()
    entry.element.remove()
    overlayStack.pop()

    if (overlayStack.length > 0) {
        isolateBackground()
    } else {
        document.body.classList.remove('capell-interaction-open')
        restoreBackground()
        window.scrollTo({ top: preservedScrollY, behavior: 'instant' })
    }

    if (entry.trigger.isConnected) {
        entry.trigger.focus({ preventScroll: true })
    }
}

const bindOverlayBehavior = (entry, trigger) => {
    const { element, controller } = entry
    const close = () => closeOverlay(entry)

    element
        .querySelector('[data-capell-interaction-close]')
        .addEventListener('click', close, { signal: controller.signal })

    element.addEventListener(
        'click',
        (event) => {
            if (
                event.target === element &&
                trigger.dataset.capellInteractionCloseOnBackdrop !== 'false' &&
                overlayStack.at(-1) === entry
            ) {
                close()
            }
        },
        { signal: controller.signal },
    )

    document.addEventListener(
        'keydown',
        (event) => {
            if (overlayStack.at(-1) !== entry) {
                return
            }

            if (event.key === 'Escape') {
                event.preventDefault()
                close()

                return
            }

            if (event.key !== 'Tab') {
                return
            }

            const focusable = focusableElements(element)
            if (focusable.length === 0) {
                event.preventDefault()
                element.querySelector('[role="dialog"]')?.focus()

                return
            }

            const first = focusable[0]
            const last = focusable.at(-1)
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault()
                last.focus()
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault()
                first.focus()
            }
        },
        { signal: controller.signal },
    )
}

const fallbackMarkup = (trigger) => {
    const fallbackUrl = canonicalUrl(trigger.href)
    const retryLabel = localized(trigger, 'RetryLabel', 'Retry')
    const fallbackLabel = localized(
        trigger,
        'FallbackLabel',
        'Open the full page',
    )

    return `
        <p class="capell-interaction-error-message">${escapeHtml(localized(trigger, 'ErrorLabel', 'Content could not be loaded.'))}</p>
        <div class="capell-interaction-error-actions">
            <button type="button" class="capell-interaction-retry" data-capell-interaction-retry>${escapeHtml(retryLabel)}</button>
            ${fallbackUrl ? `<a href="${escapeHtml(fallbackUrl)}" class="capell-interaction-fallback">${escapeHtml(fallbackLabel)}</a>` : ''}
        </div>
    `
}

const setTriggerLoading = (trigger, loading, message) => {
    trigger.setAttribute('aria-busy', loading ? 'true' : 'false')
    const group = trigger.closest('.capell-interactions')
    const status = group?.querySelector('[data-capell-interaction-status]')
    if (status) {
        status.textContent = message || ''
    }
}

const hydrateInteractionContent = async (trigger, content, retry = false) => {
    const payload = await fetchInteractionPayload(trigger)
    content.innerHTML = payload.html
    const assets = await loadKnownResourceIds(content, payload.resourceIds, {
        retry,
    })
    activateNestedRuntime(content)

    return assets
}

const showOverlayError = (entry, trigger) => {
    const content = entry.element.querySelector(
        '[data-capell-interaction-content]',
    )
    const status = entry.element.querySelector(
        '[data-capell-interaction-status]',
    )
    content.innerHTML = fallbackMarkup(trigger)
    status.textContent = localized(
        trigger,
        'ErrorLabel',
        'Content could not be loaded.',
    )

    content
        .querySelector('[data-capell-interaction-retry]')
        ?.addEventListener(
            'click',
            () => loadOverlayContent(entry, trigger, true),
            { signal: entry.controller.signal },
        )
}

const loadOverlayContent = async (entry, trigger, retry = false) => {
    const content = entry.element.querySelector(
        '[data-capell-interaction-content]',
    )
    const status = entry.element.querySelector(
        '[data-capell-interaction-status]',
    )
    status.textContent = localized(trigger, 'LoadingLabel', 'Loading content')
    entry.element.setAttribute('aria-busy', 'true')

    try {
        const assets = await hydrateInteractionContent(trigger, content, retry)
        status.textContent =
            assets.failures.length === 0
                ? localized(trigger, 'ReadyLabel', 'Content loaded')
                : localized(
                      trigger,
                      'AssetErrorLabel',
                      'Content loaded. Some enhancements are unavailable.',
                  )
    } catch {
        showOverlayError(entry, trigger)
    } finally {
        entry.element.setAttribute('aria-busy', 'false')
    }
}

const openOverlay = async (trigger, slideOver = false) => {
    const id = `capell-interaction-${crypto.randomUUID?.() || Date.now()}`
    const titleId = `${id}-title`
    const label = triggerLabel(trigger)
    const closeLabel = localized(trigger, 'CloseLabel', 'Close')
    const overlay = document.createElement('div')
    overlay.className = slideOver
        ? 'capell-interaction-overlay capell-interaction-overlay--slide-over'
        : 'capell-interaction-overlay'
    overlay.innerHTML = `
        <div class="${modalClassFor(trigger)}" role="dialog" aria-modal="true" aria-labelledby="${titleId}" tabindex="-1">
            <h2 id="${titleId}" class="capell-interaction-title">${escapeHtml(label)}</h2>
            <button type="button" class="capell-interaction-close" data-capell-interaction-close aria-label="${escapeHtml(closeLabel)}">
                <span aria-hidden="true">×</span>
                <span class="capell-interaction-visually-hidden">${escapeHtml(closeLabel)}</span>
            </button>
            <p class="capell-interaction-status capell-interaction-visually-hidden" role="status" aria-live="polite" data-capell-interaction-status></p>
            <div class="capell-interaction-content" data-capell-interaction-content></div>
        </div>
    `

    if (overlayStack.length === 0) {
        preservedScrollY = window.scrollY
        document.body.classList.add('capell-interaction-open')
    }

    const entry = {
        element: overlay,
        trigger,
        controller: new AbortController(),
    }
    document.body.appendChild(overlay)
    overlayStack.push(entry)
    isolateBackground()
    bindOverlayBehavior(entry, trigger)
    overlay.querySelector('[data-capell-interaction-close]').focus()
    await loadOverlayContent(entry, trigger)
}

const showInlineError = (trigger, container, retry) => {
    container.innerHTML = fallbackMarkup(trigger)
    container.setAttribute('aria-busy', 'false')
    container
        .querySelector('[data-capell-interaction-retry]')
        ?.addEventListener('click', () => retry(true), { once: true })
}

const revealInline = async (trigger, replace = false, retryAssets = false) => {
    const container = document.createElement('section')
    container.className = 'capell-interaction-inline'
    container.tabIndex = -1
    container.setAttribute('role', 'region')
    container.setAttribute('aria-label', triggerLabel(trigger))
    trigger
        .closest('.capell-interactions')
        ?.insertAdjacentElement('afterend', container)

    const load = async (retry = false) => {
        container.setAttribute('aria-busy', 'true')
        try {
            const assets = await hydrateInteractionContent(
                trigger,
                container,
                retry,
            )
            container.setAttribute('aria-busy', 'false')
            container.dataset.capellInteractionAssetStatus =
                assets.failures.length === 0 ? 'ready' : 'failed'

            if (replace) {
                trigger.closest('.capell-interactions')?.remove()
            }

            container.focus({ preventScroll: true })
        } catch {
            showInlineError(trigger, container, load)
        }
    }

    await load(retryAssets)
}

const handleInteraction = async (trigger) => {
    if (trigger.dataset.capellInteractionLoading === 'true') {
        return
    }

    trigger.dataset.capellInteractionLoading = 'true'
    setTriggerLoading(
        trigger,
        true,
        localized(trigger, 'LoadingLabel', 'Loading content'),
    )

    try {
        const behavior = trigger.dataset.capellInteractionBehavior

        if (behavior === 'slide_over') {
            await openOverlay(trigger, true)
        } else if (behavior === 'inline_reveal') {
            await revealInline(trigger)
        } else if (behavior === 'replace_region') {
            await revealInline(trigger, true)
        } else {
            await openOverlay(trigger)
        }
    } finally {
        delete trigger.dataset.capellInteractionLoading
        setTriggerLoading(trigger, false, '')
    }
}

const cleanupDetachedBindings = () => {
    interactionBindings.forEach((binding) => {
        if (!binding.trigger.isConnected) {
            binding.controller.abort()
            interactionBindings.delete(binding)
        }
    })
}

const cleanupDetachedOverlays = () => {
    for (let index = overlayStack.length - 1; index >= 0; index -= 1) {
        const entry = overlayStack[index]
        if (entry.element.isConnected) {
            continue
        }

        entry.controller.abort()
        overlayStack.splice(index, 1)
    }

    if (overlayStack.length > 0) {
        isolateBackground()

        return
    }

    const wasOpen = document.body.classList.contains('capell-interaction-open')
    document.body.classList.remove('capell-interaction-open')
    restoreBackground()
    if (wasOpen) {
        window.scrollTo({ top: preservedScrollY, behavior: 'auto' })
    }
}

const shouldIntercept = (event) =>
    !event.defaultPrevented &&
    event.button === 0 &&
    !event.metaKey &&
    !event.ctrlKey &&
    !event.shiftKey &&
    !event.altKey

const initInteractions = (root = document) => {
    cleanupDetachedBindings()

    root.querySelectorAll('a[data-capell-interaction]').forEach((trigger) => {
        if (trigger.dataset.capellInteractionBound === 'true') {
            return
        }

        const controller = new AbortController()
        trigger.dataset.capellInteractionBound = 'true'
        interactionBindings.add({ trigger, controller })
        trigger.addEventListener(
            'click',
            (event) => {
                if (!shouldIntercept(event)) {
                    return
                }

                event.preventDefault()
                handleInteraction(trigger).catch(() => {})
            },
            { signal: controller.signal },
        )
    })
}

const init = () => {
    cleanupDetachedOverlays()
    seedDocumentResources()
    initWidgetRuntime()
    initFragments()
    initInteractions()
}

window.CapellWidgetRuntime = Object.freeze({
    init,
    retryResources: (element, resourceIds) =>
        loadKnownResourceIds(
            element instanceof Element ? element : document,
            Array.isArray(resourceIds) ? resourceIds : [],
            { retry: true },
        ),
})

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true })
} else {
    init()
}

document.addEventListener('livewire:navigated', init)
