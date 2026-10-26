export default function capellRepeaterTabs({ activeTab }) {
    return {
        tab: activeTab,
        focusedTab: null,
        tablistHasFocus: false,

        tabs() {
            return Array.from(
                this.$refs.tablist.querySelectorAll('[role="tab"]'),
            ).filter(
                (tab) => tab.closest('[role="tablist"]') === this.$refs.tablist,
            )
        },

        focusTab(index) {
            const tabs = this.tabs()
            if (!tabs.length) return
            const target = tabs[(index + tabs.length) % tabs.length]
            this.focusedTab = Number(target.dataset.tabIndex)
            target.focus()
        },

        keydown(event) {
            const tabs = this.tabs()
            const current = tabs.indexOf(event.target)
            if (
                current === -1 ||
                event.altKey ||
                event.ctrlKey ||
                event.metaKey
            )
                return

            switch (event.key) {
                case 'ArrowLeft':
                    event.preventDefault()
                    this.focusTab(current + (this.isRtl() ? 1 : -1))
                    break
                case 'ArrowRight':
                    event.preventDefault()
                    this.focusTab(current + (this.isRtl() ? -1 : 1))
                    break
                case 'Home':
                    event.preventDefault()
                    this.focusTab(0)
                    break
                case 'End':
                    event.preventDefault()
                    this.focusTab(tabs.length - 1)
                    break
                case 'Enter':
                case ' ':
                    event.preventDefault()
                    this.activate(Number(event.target.dataset.tabIndex))
                    break
            }
        },

        isRtl() {
            const tablist = this.$refs.tablist
            return (
                tablist.ownerDocument.defaultView.getComputedStyle(tablist)
                    .direction === 'rtl'
            )
        },

        blur(event) {
            if (
                event.relatedTarget &&
                !this.$refs.tablist.contains(event.relatedTarget)
            ) {
                this.focusedTab = null
                this.tablistHasFocus = false
            }
        },

        activate(index) {
            this.tab = index
            this.focusedTab = index
        },

        refresh(index) {
            const hadTabFocus =
                this.$refs.tablist?.contains(document.activeElement) ||
                ((this.focusedTab !== null || this.tablistHasFocus) &&
                    document.activeElement === document.body)
            this.activate(Math.max(1, index))
            if (index === 0) {
                this.focusedTab = null
                this.tablistHasFocus = false
            }
            if (hadTabFocus) {
                this.$nextTick(() => {
                    if (index === 0) {
                        this.$refs.emptyState?.querySelector('button')?.focus()
                    } else {
                        this.focusTab(index - 1)
                    }
                })
            }
        },
    }
}
