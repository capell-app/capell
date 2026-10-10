// Work ownership follows physical elements, including restored history snapshots.
const pendingLoads = new WeakSet()
const beginElementWork = (element) => {
    if (pendingLoads.has(element)) return null
    pendingLoads.add(element)
    let active = true
    return () => {
        if (!active) return
        active = false
        pendingLoads.delete(element)
    }
}

const cachePrefix = 'capell:deferred-fragment:v2:'
const legacyCachePrefix = 'capell:deferred-fragment:v1:'
const cacheMaxAge = 30 * 60 * 1000

const publicCacheMaxAge = (cacheControl) => {
    if (typeof cacheControl !== 'string') return 0
    const directives = cacheControl
        .toLowerCase()
        .split(',')
        .map((value) => value.trim())
    const names = directives.map((value) => value.split('=')[0].trim())
    if (
        !names.includes('public') ||
        names.some((name) => ['private', 'no-store', 'no-cache'].includes(name))
    )
        return 0
    const ages = directives.filter((value) => /^max-age\s*=/.test(value))
    if (ages.length !== 1 || !/^max-age\s*=\s*\d+$/.test(ages[0])) return 0
    const milliseconds = Number(ages[0].split('=')[1].trim()) * 1000
    return Number.isFinite(milliseconds) && milliseconds > 0
        ? Math.min(milliseconds, cacheMaxAge)
        : 0
}

// Preserve server cache policy at the transport boundary; unknown responses are never restored.
export const readDeferredFragmentResponse = async (response) => {
    const cacheControl = response.headers.get('Cache-Control')
    return {
        html: await response.text(),
        cacheable: publicCacheMaxAge(cacheControl) > 0,
        cacheControl,
    }
}

const htmlWithoutHarmlessPreamble = (html) => {
    if (typeof html !== 'string') return ''

    let remaining = html.replace(/^\uFEFF/, '').trimStart()

    while (remaining !== '') {
        const preamble = remaining.match(/^(?:<!--[\s\S]*?-->|<\?[\s\S]*?\?>)/)

        if (preamble === null) break

        remaining = remaining.slice(preamble[0].length).trimStart()
    }

    return remaining
}

const isValidHtml = (html) => {
    const candidate = htmlWithoutHarmlessPreamble(html)

    if (candidate === '') return false

    return (
        !/^<!doctype(?:\s|>)/i.test(candidate) &&
        !/^<(?:html|head|body)(?:\s|>)/i.test(candidate)
    )
}

export const createDeferredFragments = ({
    fetchHtmlFragment,
    initializeFragment = () => {},
    onLoaded = () => {},
    onError = () => {},
    onIdle = (callback) => window.setTimeout(callback, 600),
    retryDelay = 1200,
}) => {
    const intentCleanups = new Map()
    const loadGenerations = new WeakMap()
    const initialisedFragments = new WeakSet()
    const retriedFragments = new WeakSet()
    const observedTargets = new Set()
    let observer = null

    const isCurrentFragment = (fragment, generation = null) =>
        fragment.isConnected &&
        (generation === null || loadGenerations.get(fragment) === generation)

    const beginLoadGeneration = (fragment) => {
        const generation = (loadGenerations.get(fragment) ?? 0) + 1

        loadGenerations.set(fragment, generation)

        return generation
    }

    const releaseObservedTarget = (fragment) => {
        if (!observedTargets.delete(fragment)) return

        observer?.unobserve(fragment)

        if (observedTargets.size === 0) {
            observer?.disconnect()
            observer = null
        }
    }

    const cleanupIntent = (fragment) => {
        intentCleanups.get(fragment)?.()
    }

    const releaseFragment = (fragment) => {
        cleanupIntent(fragment)
        releaseObservedTarget(fragment)
    }

    const cacheKey = (fragment, url) => {
        const fragmentKey = fragment.dataset.deferredFragmentKey

        if (fragmentKey) return `${cachePrefix}${fragmentKey}`

        try {
            return `${cachePrefix}${new URL(url, window.location.href).href}`
        } catch {
            return null
        }
    }

    const discardCachedHtml = (fragment, url) => {
        const key = cacheKey(fragment, url)

        if (key === null) return

        try {
            window.sessionStorage.removeItem(key)
            window.sessionStorage.removeItem(
                key.replace(cachePrefix, legacyCachePrefix),
            )
        } catch {
            // Fragment restoration is optional when storage is unavailable.
        }
    }

    const cachedHtml = (fragment, url, generation = null) => {
        if (!isCurrentFragment(fragment, generation)) return null

        const key = cacheKey(fragment, url)

        if (key === null) return null

        try {
            // Legacy entries did not record response privacy and cannot be trusted.
            window.sessionStorage.removeItem(
                key.replace(cachePrefix, legacyCachePrefix),
            )
            const value = window.sessionStorage.getItem(key)

            if (value === null) return null

            const payload = JSON.parse(value)

            if (
                payload?.cacheable !== true ||
                publicCacheMaxAge(payload?.cacheControl) === 0 ||
                typeof payload?.html !== 'string' ||
                typeof payload?.storedAt !== 'number' ||
                Date.now() - payload.storedAt < 0 ||
                Date.now() - payload.storedAt >
                    publicCacheMaxAge(payload.cacheControl) ||
                !isValidHtml(payload.html)
            ) {
                discardCachedHtml(fragment, url)

                return null
            }

            return payload.html
        } catch {
            discardCachedHtml(fragment, url)

            return null
        }
    }

    const rememberHtml = (fragment, url, result, generation = null) => {
        const { html, cacheControl } = result
        if (!isCurrentFragment(fragment, generation)) return

        const key = cacheKey(fragment, url)

        if (key === null || !isValidHtml(html)) return

        try {
            window.sessionStorage.setItem(
                key,
                JSON.stringify({
                    html,
                    storedAt: Date.now(),
                    cacheable: true,
                    cacheControl,
                }),
            )
        } catch {
            // Fragment restoration is optional when storage is unavailable or full.
        }
    }

    const applyHtml = (fragment, html, generation = null) => {
        if (
            !isCurrentFragment(fragment, generation) ||
            fragment.dataset.deferredFragmentLoaded === 'true' ||
            !isValidHtml(html)
        ) {
            return false
        }

        releaseFragment(fragment)
        fragment.hidden = false
        fragment.innerHTML = html
        fragment.dataset.deferredFragmentState = 'loaded'
        fragment.dataset.deferredFragmentLoaded = 'true'
        fragment.removeAttribute('aria-busy')

        onLoaded(fragment)

        if (!isCurrentFragment(fragment, generation)) return false

        initializeFragment(fragment)

        if (!isCurrentFragment(fragment, generation)) return false

        document.dispatchEvent(
            new CustomEvent('capell:deferred-fragment-loaded', {
                detail: { fragment },
            }),
        )

        return true
    }

    const fail = (fragment, generation) => {
        if (!isCurrentFragment(fragment, generation)) return

        releaseFragment(fragment)
        fragment.dataset.deferredFragmentState = 'error'
        fragment.removeAttribute('aria-busy')
        onError(fragment)
    }

    const restore = (fragment, generation = null) => {
        if (!isCurrentFragment(fragment, generation)) return false

        const url = fragment.dataset.deferredFragmentUrl

        if (!url || fragment.dataset.deferredFragmentLoaded === 'true') {
            if (fragment.dataset.deferredFragmentLoaded === 'true') {
                releaseFragment(fragment)
            }

            return fragment.dataset.deferredFragmentLoaded === 'true'
        }

        const html = cachedHtml(fragment, url, generation)

        return html !== null && applyHtml(fragment, html, generation)
    }

    const load = (fragment) => {
        if (!isCurrentFragment(fragment)) return

        const url = fragment.dataset.deferredFragmentUrl
        const state = fragment.dataset.deferredFragmentState

        releaseFragment(fragment)

        if (
            !url ||
            state === 'error' ||
            fragment.dataset.deferredFragmentLoaded === 'true'
        ) {
            return
        }

        if (restore(fragment)) return

        const releaseWork = beginElementWork(fragment, 'deferredFragment')
        if (!releaseWork) return

        if (!retriedFragments.has(fragment)) {
            delete fragment.dataset.deferredFragmentRetried
        }

        const generation = beginLoadGeneration(fragment)

        fragment.dataset.deferredFragmentState = 'loading'
        fragment.setAttribute('aria-busy', 'true')

        return Promise.resolve()
            .then(() => fetchHtmlFragment(url))
            .then((result) => {
                if (!isCurrentFragment(fragment, generation)) return
                const html = typeof result === 'string' ? result : result?.html

                if (!isValidHtml(html)) {
                    throw new Error('Deferred fragment returned invalid HTML.')
                }

                if (
                    result?.cacheable === true &&
                    publicCacheMaxAge(result.cacheControl) > 0
                ) {
                    rememberHtml(fragment, url, result, generation)
                } else {
                    discardCachedHtml(fragment, url)
                }
                applyHtml(fragment, html, generation)
            })
            .catch(() => {
                if (!isCurrentFragment(fragment, generation)) return

                if (!retriedFragments.has(fragment)) {
                    retriedFragments.add(fragment)
                    fragment.dataset.deferredFragmentRetried = 'true'
                    fragment.dataset.deferredFragmentState = 'retrying'
                    return new Promise((resolve) => {
                        window.setTimeout(() => {
                            releaseWork()
                            if (
                                !isCurrentFragment(fragment, generation) ||
                                fragment.dataset.deferredFragmentLoaded ===
                                    'true' ||
                                fragment.dataset.deferredFragmentState ===
                                    'error'
                            ) {
                                resolve()
                                return
                            }

                            resolve(load(fragment))
                        }, retryDelay)
                    })
                }

                fail(fragment, generation)
            })
            .finally(releaseWork)
    }

    const registerIntent = (fragment) => {
        if (intentCleanups.has(fragment)) return

        const loadOnIntent = () => {
            cleanup()
            load(fragment)
        }
        const cleanup = () => {
            window.removeEventListener('scroll', loadOnIntent)
            window.removeEventListener('wheel', loadOnIntent)
            window.removeEventListener('touchmove', loadOnIntent)
            intentCleanups.delete(fragment)
        }

        intentCleanups.set(fragment, cleanup)
        window.addEventListener('scroll', loadOnIntent, { passive: true })
        window.addEventListener('wheel', loadOnIntent, { passive: true })
        window.addEventListener('touchmove', loadOnIntent, { passive: true })
        onIdle(() => {
            cleanup()
            load(fragment)
        })
    }

    const observe = (fragment) => {
        if (observer === null) {
            observer = new IntersectionObserver(
                (entries) => {
                    entries.forEach((entry) => {
                        if (
                            !entry.isIntersecting ||
                            !observedTargets.has(entry.target)
                        ) {
                            return
                        }

                        releaseObservedTarget(entry.target)
                        load(entry.target)
                    })
                },
                { rootMargin: '2400px 0px' },
            )
        }

        observedTargets.add(fragment)
        observer.observe(fragment)
    }

    return (root = document) => {
        Array.from(intentCleanups).forEach(([fragment, cleanup]) => {
            if (!fragment.isConnected) cleanup()
        })
        Array.from(observedTargets).forEach((fragment) => {
            if (!fragment.isConnected) releaseObservedTarget(fragment)
        })

        const fragments = Array.from(
            root.querySelectorAll('[data-deferred-fragment]'),
        )

        if (fragments.length === 0) return

        const pendingFragments = fragments.filter((fragment) => {
            if (restore(fragment)) return false

            return !initialisedFragments.has(fragment)
        })

        if (pendingFragments.length === 0) return

        const intersectionFragments = []
        pendingFragments.forEach((fragment) => {
            initialisedFragments.add(fragment)

            if (fragment.dataset.deferredFragmentStrategy === 'scroll') {
                registerIntent(fragment)

                return
            }

            if (fragment.dataset.deferredFragmentStrategy === 'idle') {
                onIdle(() => load(fragment))

                return
            }

            intersectionFragments.push(fragment)
        })

        if (intersectionFragments.length === 0) return

        if (!('IntersectionObserver' in window)) {
            intersectionFragments.forEach((fragment) => {
                onIdle(() => load(fragment))
            })

            return
        }

        intersectionFragments.forEach(observe)
    }
}
