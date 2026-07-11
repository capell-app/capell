function r(o) {
    return {
        pendingKey: null,
        pendingTimer: null,
        chordTimeoutMs: 1e3,
        init() {
            window.addEventListener('keydown', (e) => this.handleKeydown(e))
        },
        handleKeydown(e) {
            let t = e.target
            if (
                t instanceof HTMLInputElement ||
                t instanceof HTMLTextAreaElement ||
                t instanceof HTMLSelectElement ||
                (t instanceof HTMLElement && t.isContentEditable)
            )
                return
            let i = e.key.toLowerCase()
            if (this.pendingKey !== null) {
                let n = this.pendingKey + ' ' + i
                ;(clearTimeout(this.pendingTimer), (this.pendingKey = null))
                let a = o.find((l) => l.sequence === n)
                if (a) {
                    ;(e.preventDefault(), this.executeShortcut(a))
                    return
                }
            }
            let s = o.find((n) => n.key === i && !n.sequence)
            if (s) {
                ;(e.preventDefault(), this.executeShortcut(s))
                return
            }
            o.filter((n) => n.sequence && n.sequence.startsWith(i + ' '))
                .length > 0 &&
                ((this.pendingKey = i),
                (this.pendingTimer = setTimeout(() => {
                    this.pendingKey = null
                }, this.chordTimeoutMs)))
        },
        executeShortcut(e) {
            if (e.action === 'navigate') {
                let t = this.detectPanelBase()
                window.location.href = t + '/' + e.target
            } else if (e.action === 'focus') {
                let t = document.querySelector(e.target)
                t instanceof HTMLElement && t.focus()
            }
        },
        detectPanelBase() {
            let e = window.location.pathname.split('/').filter(Boolean)
            return e.length > 0 ? '/' + e[0] : '/admin'
        },
    }
}
export { r as default }
