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

        listenerController: null,

        init() {
            this.destroy()
            this.listenerController = new AbortController()
            const { signal } = this.listenerController
            window.addEventListener(
                'keydown',
                (event) => this.handleKeydown(event),
                { signal },
            )
            document.addEventListener(
                'livewire:navigating',
                () => this.destroy(),
                { signal },
            )
        },

        destroy() {
            this.listenerController?.abort()
            this.listenerController = null
            clearTimeout(this.pendingTimer)
            this.pendingTimer = null
            this.pendingKey = null
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

            if (event.ctrlKey || event.metaKey || event.altKey) {
                clearTimeout(this.pendingTimer)
                this.pendingKey = null
                this.pendingTimer = null
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
                const palette = document.querySelector('[data-command-palette]')
                const id = palette?.getAttribute('wire:id')
                if (id && shortcut.commandId) {
                    window.Livewire?.find(id)?.call(
                        'runCommand',
                        shortcut.commandId,
                    )
                }
            } else if (shortcut.action === 'focus') {
                const element = document.querySelector(shortcut.target)
                if (element instanceof HTMLElement) {
                    element.focus()
                }
            }
        },
    }
}
