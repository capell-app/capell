;(() => {
    const selector = 'link[data-capell-stylesheet-recovery]'
    const retryDelays = [1000, 3000, 10000, 30000, 60000]
    const recovering = new WeakSet()

    const emit = (type, link) => {
        document.dispatchEvent(
            new CustomEvent(type, {
                detail: {
                    href: link.getAttribute?.('href') || link.href || '',
                },
            }),
        )
    }

    const markFallback = () => {
        const root = document.documentElement
        root.dataset.frontendStyles = 'fallback'
        root.classList.add('capell-frontend-styles-fallback')
        emit('capell:stylesheet-fallback', root)
    }

    const markLoaded = (link) => {
        const root = document.documentElement
        root.dataset.frontendStyles = 'loaded'
        root.dataset.frontendStylesLoaded = 'true'
        root.classList.add('capell-frontend-styles-loaded')
        root.classList.remove('capell-frontend-styles-fallback')
        document
            .querySelectorAll('link[data-capell-stylesheet-fallback-active]')
            .forEach((fallback) => fallback.remove())
        emit('capell:stylesheet-recovered', link)
    }

    const activate = (link) => {
        link.rel = 'stylesheet'
        link.media = 'all'
        link.removeAttribute('onload')
        link.removeAttribute('onerror')
    }

    const ensureFallback = (link) => {
        const href = link.dataset.capellStylesheetFallback
        if (
            !href ||
            document.querySelector(
                'link[data-capell-stylesheet-fallback-active]',
            )
        ) {
            return
        }

        const fallback = document.createElement('link')
        fallback.rel = 'stylesheet'
        fallback.href = href
        fallback.dataset.capellStylesheetFallbackActive = 'true'
        fallback.addEventListener('load', markFallback, { once: true })
        document.head.appendChild(fallback)
    }

    const retryUrl = (href, attempt) => {
        const url = new URL(href, document.baseURI || window.location.href)
        url.searchParams.set('capell_asset_retry', String(attempt + 1))

        return url.href
    }

    const scheduleRetry = (link, attempt = 0) => {
        if (attempt >= retryDelays.length) {
            return
        }

        window.setTimeout(() => {
            const retry = link.cloneNode(false)
            retry.href = retryUrl(link.href, attempt)
            retry.dataset.capellStylesheetRetry = 'true'
            retry.removeAttribute('data-capell-stylesheet-recovery')
            retry.removeAttribute('data-capell-stylesheet-fallback')
            retry.removeAttribute('onload')
            retry.removeAttribute('onerror')
            retry.addEventListener(
                'load',
                () => {
                    activate(retry)
                    link.remove()
                    markLoaded(retry)
                },
                { once: true },
            )
            retry.addEventListener(
                'error',
                () => {
                    retry.remove()
                    scheduleRetry(link, attempt + 1)
                },
                { once: true },
            )
            document.head.appendChild(retry)
        }, retryDelays[attempt])
    }

    const recover = (link) => {
        if (recovering.has(link)) {
            return
        }

        recovering.add(link)
        ensureFallback(link)
        scheduleRetry(link)
    }

    document.addEventListener(
        'error',
        (event) => {
            if (event.target?.matches?.(selector)) {
                recover(event.target)
            }
        },
        true,
    )
    document.addEventListener(
        'load',
        (event) => {
            if (event.target?.matches?.(selector)) {
                activate(event.target)
                markLoaded(event.target)
            }
        },
        true,
    )
})()
