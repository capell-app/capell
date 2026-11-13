// Capell's page-view counter. Inlined into public pages, with the endpoint replaced by the
// site's absolute activity URL and the counter flag by the site's first-party counter
// setting. Granted analytics sends a page view. Undecided analytics sends one only under the
// first-party counter, which stores no cookie or visitor identifier. A refusal, an opt-out
// signal, a missing consent runtime or a runtime that fails sends nothing.
;(() => {
    const firstPartyCounter = false
    // A missing runtime or one that throws leaves consent unreadable, so nothing is sent.
    let decision
    try {
        decision = window.CapellPrivacy.allows('analytics')
    } catch {
        return
    }
    const optedOut =
        navigator.doNotTrack === '1' || navigator.globalPrivacyControl === true
    const counts =
        decision === true ||
        (decision === null && firstPartyCounter && !optedOut)
    if (!counts) return

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
