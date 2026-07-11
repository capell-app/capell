/**
 * Capell Admin — Keyboard shortcuts Alpine component.
 *
 * Registered as an Alpine component and mounted once on the admin body.
 * Supports:
 *   - Key sequences (e.g. "g p") with a 1 000 ms chord timeout.
 *   - Single key presses (e.g. "/").
 *
 * Configuration is injected from PHP via capell-admin.shortcuts config key.
 */
export default function capellKeyboardShortcuts(shortcuts) {
    return {
        pendingKey: null,
        pendingTimer: null,
        chordTimeoutMs: 1000,

        init() {
            window.addEventListener('keydown', (event) =>
                this.handleKeydown(event),
            )
        },

        handleKeydown(event) {
            // Ignore shortcuts when the user is typing in an input, textarea, or
            // contenteditable element to avoid hijacking normal text entry.
            const target = event.target
            if (
                target instanceof HTMLInputElement ||
                target instanceof HTMLTextAreaElement ||
                target instanceof HTMLSelectElement ||
                (target instanceof HTMLElement && target.isContentEditable)
            ) {
                return
            }

            const pressedKey = event.key.toLowerCase()

            // Resolve a pending chord first.
            if (this.pendingKey !== null) {
                const chordSequence = this.pendingKey + ' ' + pressedKey
                clearTimeout(this.pendingTimer)
                this.pendingKey = null

                const sequenceShortcut = shortcuts.find(
                    (shortcut) => shortcut.sequence === chordSequence,
                )
                if (sequenceShortcut) {
                    event.preventDefault()
                    this.executeShortcut(sequenceShortcut)
                    return
                }
            }

            // Check for single-key shortcuts.
            const singleShortcut = shortcuts.find(
                (shortcut) => shortcut.key === pressedKey && !shortcut.sequence,
            )
            if (singleShortcut) {
                event.preventDefault()
                this.executeShortcut(singleShortcut)
                return
            }

            // Check if this key could start a chord sequence.
            const potentialChordStarters = shortcuts.filter(
                (shortcut) =>
                    shortcut.sequence &&
                    shortcut.sequence.startsWith(pressedKey + ' '),
            )
            if (potentialChordStarters.length > 0) {
                this.pendingKey = pressedKey
                this.pendingTimer = setTimeout(() => {
                    this.pendingKey = null
                }, this.chordTimeoutMs)
            }
        },

        executeShortcut(shortcut) {
            if (shortcut.action === 'navigate') {
                // target is a named route segment — resolve via Filament's
                // panel URL conventions. Build the URL client-side from the
                // current panel path prefix.
                const panelBase = this.detectPanelBase()
                window.location.href = panelBase + '/' + shortcut.target
            } else if (shortcut.action === 'focus') {
                const element = document.querySelector(shortcut.target)
                if (element instanceof HTMLElement) {
                    element.focus()
                }
            }
        },

        detectPanelBase() {
            // Detect the admin panel base path from the current URL.
            // Filament panel URLs follow: /admin/... — walk back to the
            // first path segment that looks like the panel prefix.
            const pathSegments = window.location.pathname
                .split('/')
                .filter(Boolean)
            // Default to /admin — the typical panel prefix.
            if (pathSegments.length > 0) {
                return '/' + pathSegments[0]
            }
            return '/admin'
        },
    }
}
