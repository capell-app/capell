// Capell's page-view counter. Inlined into public pages, with the endpoint replaced by the
// site's absolute activity URL. It sends a page view only when analytics is granted:
// undecided or refused sends nothing.
;(() => {
    const privacy = window.CapellPrivacy
    const allowed = privacy
        ? privacy.allows?.('analytics') === true
        : navigator.doNotTrack !== '1' &&
          navigator.globalPrivacyControl !== true
    if (!allowed) return

    const endpoint = '/_capell/activity'
    const payload = JSON.stringify({
        type: 'page_view',
        path: window.location.pathname,
    })
    if (navigator.sendBeacon) {
        navigator.sendBeacon(
            endpoint,
            new Blob([payload], { type: 'application/json' }),
        )
        return
    }
    fetch(endpoint, {
        method: 'POST',
        body: payload,
        headers: { 'Content-Type': 'application/json' },
        credentials: 'omit',
        keepalive: true,
    }).catch(() => {})
})()
