export default function capellContentLockHeartbeat(config) {
    return {
        heartbeatUrl: config.heartbeatUrl ?? '',
        releaseUrl: config.releaseUrl ?? '',
        logoutUrl: config.logoutUrl ?? '',
        csrfToken: config.csrfToken ?? '',
        intervalMs: config.intervalMs ?? 30000,
        localDraftStorageKey: config.storageKey ?? '',
        formSelector: config.formSelector ?? '#form',
        localDraftDebounceMs: config.localDraftDebounceMs ?? 750,
        localDraftTtlMs: config.localDraftTtlMs ?? 86400000,
        localDraftVersion: config.localDraftVersion ?? 1,
        localDraftAvailableMessage: config.localDraftAvailableMessage ?? '',
        localDraftRestoreLabel: config.localDraftRestoreLabel ?? '',
        localDraftDiscardLabel: config.localDraftDiscardLabel ?? '',
        contentLockReadOnlyMessage: config.contentLockReadOnlyMessage ?? '',
        contentLockPermissionMessage: config.contentLockPermissionMessage ?? '',
        contentLockUnavailableMessage:
            config.contentLockUnavailableMessage ?? '',
        contentLockTakeoverLabel: config.contentLockTakeoverLabel ?? '',
        timer: null,
        localDraftTimer: null,
        form: null,
        formObserver: null,
        logoutForm: null,
        logoutSubmitHandler: null,
        logoutPending: false,
        initialDataHash: null,
        localDraftTrackingStarted: false,
        localDraft: null,
        localDraftAvailable: false,
        heartbeatRequestId: 0,
        conflict: Boolean(config.initialConflict),
        permissionBlocked: false,
        heartbeatUnavailable: false,
        readOnly: Boolean(config.initialConflict),
        canRequestTakeover: Boolean(config.initialConflict),
        released: false,

        init() {
            this.loadLocalDraft()
            this.applyReadOnly()

            if (typeof this.$wire?.$hook === 'function') {
                this.$wire.$hook('commit', ({ commit, succeed }) => {
                    const calls = Array.isArray(commit?.calls)
                        ? commit.calls
                        : []
                    const isSave = calls.some((call) =>
                        [
                            'save',
                            'saveAsDraft',
                            'saveAsDraftWithLocation',
                        ].includes(call?.method),
                    )

                    if (isSave) {
                        return
                    }

                    succeed(() => {
                        this.$nextTick(() => {
                            if (!this.localDraftTrackingStarted) {
                                this.loadLocalDraft()
                            } else {
                                this.queueLocalDraft()
                            }
                        })
                        this.heartbeat()
                    })
                })
            }

            this.$nextTick(() => {
                if (!this.localDraftTrackingStarted) {
                    this.loadLocalDraft()
                }
                this.bindForm()
                this.bindLogoutForm()
            })

            this.heartbeat()
            this.startHeartbeat()

            window.addEventListener('pagehide', () => {
                this.release(true)
            })

            window.addEventListener('beforeunload', () => {
                this.release(true)
            })
        },

        bindForm() {
            if (this.form !== null) {
                return
            }

            this.form = document.querySelector(this.formSelector)

            if (!this.form) {
                return
            }

            const queueDraft = (event) => {
                if (!event.isTrusted || this.readOnly || this.released) {
                    return
                }

                this.beginLocalDraftTracking()
                this.queueLocalDraft()
            }

            // Capture before Alpine/Livewire changes data. Client hydration and
            // synthetic widget events must not create recoverable work.
            this.form.addEventListener('input', queueDraft, true)
            this.form.addEventListener('change', queueDraft, true)
            this.form.addEventListener('click', queueDraft, true)
            this.form.addEventListener('pointerdown', queueDraft, true)
            this.form.addEventListener('keydown', queueDraft, true)

            if (window.MutationObserver) {
                this.formObserver = new MutationObserver(() => {
                    if (this.readOnly) {
                        this.applyReadOnly()
                    }
                })
                this.formObserver.observe(this.form, {
                    childList: true,
                    subtree: true,
                })
            }

            this.applyReadOnly()
        },

        bindLogoutForm() {
            if (this.logoutForm !== null || this.logoutUrl === '') {
                return
            }

            this.logoutForm =
                Array.from(document.forms).find(
                    (form) => form.action === this.logoutUrl,
                ) ?? null

            if (this.logoutForm === null) {
                return
            }

            this.logoutSubmitHandler = (event) => {
                if (this.logoutPending) {
                    event.preventDefault()

                    return
                }

                if (this.released) {
                    return
                }

                event.preventDefault()
                this.logoutPending = true

                this.release().finally(() => {
                    this.logoutForm?.removeEventListener(
                        'submit',
                        this.logoutSubmitHandler,
                    )
                    this.logoutForm?.requestSubmit()
                })
            }

            this.logoutForm.addEventListener('submit', this.logoutSubmitHandler)
        },

        heartbeat() {
            if (this.released || this.heartbeatUrl === '') {
                return
            }

            const requestId = ++this.heartbeatRequestId

            window
                .fetch(this.heartbeatUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                    },
                })
                .then((response) => {
                    if (requestId !== this.heartbeatRequestId) {
                        return
                    }

                    if (response.ok) {
                        this.clearHeartbeatState()

                        return
                    }

                    if (response.status === 409) {
                        this.setConflict()

                        return
                    }

                    if (response.status === 403) {
                        this.setPermissionBlocked()

                        return
                    }

                    this.setUnavailable()
                })
                .catch(() => {
                    if (requestId === this.heartbeatRequestId) {
                        this.setUnavailable()
                    }
                })
        },

        startHeartbeat() {
            if (
                this.timer !== null ||
                this.heartbeatUrl === '' ||
                this.released
            ) {
                return
            }

            this.timer = window.setInterval(() => {
                this.heartbeat()
            }, this.intervalMs)
        },

        setConflict() {
            this.conflict = true
            this.permissionBlocked = false
            this.heartbeatUnavailable = false
            this.canRequestTakeover = true
            this.setReadOnly(true)
        },

        setPermissionBlocked() {
            this.conflict = false
            this.permissionBlocked = true
            this.heartbeatUnavailable = false
            this.canRequestTakeover = false
            this.setReadOnly(true)
        },

        setUnavailable() {
            this.conflict = false
            this.permissionBlocked = false
            this.heartbeatUnavailable = true
            this.canRequestTakeover = false
            this.setReadOnly(true)
        },

        clearHeartbeatState() {
            this.conflict = false
            this.permissionBlocked = false
            this.heartbeatUnavailable = false
            this.canRequestTakeover = false
            this.setReadOnly(false)
        },

        clearConflict() {
            this.clearHeartbeatState()
            this.startHeartbeat()
            this.heartbeat()
        },

        setReadOnly(readOnly) {
            this.readOnly = readOnly
            this.applyReadOnly()
        },

        applyReadOnly() {
            if (!this.form) {
                return
            }

            this.form.toggleAttribute(
                'data-capell-content-lock-read-only',
                this.readOnly,
            )
            this.form.toggleAttribute('inert', this.readOnly)
            this.form.setAttribute('aria-readonly', String(this.readOnly))
            this.form.setAttribute('aria-disabled', String(this.readOnly))

            this.form
                .querySelectorAll(
                    'input, textarea, select, button, [contenteditable="true"], [contenteditable=""]',
                )
                .forEach((control) => {
                    if (this.readOnly) {
                        if (
                            control.dataset
                                .capellContentLockOriginalDisabled === undefined
                        ) {
                            control.dataset.capellContentLockOriginalDisabled =
                                String(Boolean(control.disabled))
                        }

                        if ('disabled' in control) {
                            control.disabled = true
                        }

                        if (control.hasAttribute('contenteditable')) {
                            if (
                                control.dataset
                                    .capellContentLockOriginalContenteditable ===
                                undefined
                            ) {
                                control.dataset.capellContentLockOriginalContenteditable =
                                    control.getAttribute('contenteditable') ??
                                    ''
                            }

                            control.setAttribute('contenteditable', 'false')
                        }

                        return
                    }

                    if (
                        control.dataset.capellContentLockOriginalDisabled ===
                        'false'
                    ) {
                        control.disabled = false
                    }

                    delete control.dataset.capellContentLockOriginalDisabled

                    if (
                        control.dataset
                            .capellContentLockOriginalContenteditable !==
                        undefined
                    ) {
                        control.setAttribute(
                            'contenteditable',
                            control.dataset
                                .capellContentLockOriginalContenteditable,
                        )
                        delete control.dataset
                            .capellContentLockOriginalContenteditable
                    }
                })
        },

        requestTakeover() {
            if (
                !this.canRequestTakeover ||
                typeof this.$wire?.mountAction !== 'function'
            ) {
                return
            }

            this.$wire.mountAction('take-over-content-lock')
        },

        loadLocalDraft() {
            this.initialDataHash = this.dataHash()

            if (this.initialDataHash === null) {
                return
            }

            this.localDraft = this.readStoredLocalDraft()
            this.localDraftAvailable = this.localDraft !== null
        },

        beginLocalDraftTracking() {
            if (!this.localDraftTrackingStarted) {
                this.initialDataHash = this.dataHash()
                this.localDraftTrackingStarted = true
            }
        },

        queueLocalDraft() {
            if (
                !this.localDraftTrackingStarted ||
                this.readOnly ||
                this.released
            ) {
                return
            }

            if (this.localDraftTimer !== null) {
                window.clearTimeout(this.localDraftTimer)
            }

            this.localDraftTimer = window.setTimeout(() => {
                this.persistLocalDraft()
                this.localDraftTimer = null
            }, this.localDraftDebounceMs)
        },

        persistLocalDraft() {
            if (
                !this.localDraftTrackingStarted ||
                this.readOnly ||
                this.released
            ) {
                return
            }

            const data = this.currentData()

            if (data === null) {
                return
            }

            if (this.dataHash(data) === this.initialDataHash) {
                // A click without an edit must not discard work from an earlier visit.
                if (!this.localDraftAvailable) {
                    this.clearLocalDraft()
                }

                return
            }

            const draft = {
                version: this.localDraftVersion,
                data,
                savedAt: Date.now(),
            }

            try {
                if (this.localDraftStorageKey === '' || !window.localStorage) {
                    return
                }

                window.localStorage.setItem(
                    this.localDraftStorageKey,
                    JSON.stringify(draft),
                )
                // The recovery offer belongs to a previous visit, not the
                // work already visible in the current editor.
                this.localDraft = null
                this.localDraftAvailable = false
            } catch {
                // Private browsing and storage quota errors must not interrupt editing.
            }
        },

        readStoredLocalDraft() {
            if (this.localDraftStorageKey === '') {
                return null
            }

            try {
                if (!window.localStorage) {
                    return null
                }

                const rawDraft = window.localStorage.getItem(
                    this.localDraftStorageKey,
                )

                if (rawDraft === null) {
                    return null
                }

                const draft = JSON.parse(rawDraft)

                if (
                    !draft ||
                    draft.version !== this.localDraftVersion ||
                    typeof draft.data !== 'object' ||
                    draft.data === null ||
                    typeof draft.savedAt !== 'number' ||
                    !Number.isFinite(draft.savedAt) ||
                    draft.savedAt > Date.now() ||
                    Date.now() - draft.savedAt > this.localDraftTtlMs ||
                    this.dataHash(draft.data) === this.initialDataHash
                ) {
                    this.removeStoredLocalDraft()

                    return null
                }

                return draft.data
            } catch {
                return null
            }
        },

        restoreLocalDraft() {
            if (
                this.readOnly ||
                this.localDraft === null ||
                typeof this.$wire?.$set !== 'function'
            ) {
                return
            }

            const data = this.clone(this.localDraft)

            if (data === null) {
                return
            }

            this.beginLocalDraftTracking()
            this.$wire.$set('data', data)
            this.clearLocalDraft()
            this.$nextTick(() => this.queueLocalDraft())
        },

        discardLocalDraft() {
            this.clearLocalDraft()
        },

        clearLocalDraft() {
            if (this.localDraftTimer !== null) {
                window.clearTimeout(this.localDraftTimer)
                this.localDraftTimer = null
            }

            try {
                this.removeStoredLocalDraft()
            } catch {
                // Storage cleanup is best effort and must not interrupt the editor.
            }

            this.localDraft = null
            this.localDraftAvailable = false
        },

        markEditorSaved() {
            this.initialDataHash = this.dataHash()
            this.localDraftTrackingStarted = false
            this.clearLocalDraft()
        },

        removeStoredLocalDraft() {
            if (this.localDraftStorageKey !== '' && window.localStorage) {
                window.localStorage.removeItem(this.localDraftStorageKey)
            }
        },

        currentData() {
            if (typeof this.$wire?.data === 'undefined') {
                return null
            }

            return this.clone(this.$wire.data)
        },

        dataHash(data = this.currentData()) {
            const normalise = (value) => {
                if (Array.isArray(value)) {
                    return value.map(normalise)
                }

                if (value !== null && typeof value === 'object') {
                    return Object.fromEntries(
                        Object.keys(value)
                            .sort()
                            .map((key) => [key, normalise(value[key])]),
                    )
                }

                return value
            }

            return data === null ? null : JSON.stringify(normalise(data))
        },

        clone(data) {
            try {
                return JSON.parse(JSON.stringify(data))
            } catch {
                return null
            }
        },

        release(useBeacon = false) {
            if (this.released) {
                return Promise.resolve()
            }

            if (this.localDraftTimer !== null) {
                window.clearTimeout(this.localDraftTimer)
                this.localDraftTimer = null
                this.persistLocalDraft()
            }

            this.released = true
            this.stop()

            if (this.releaseUrl === '') {
                return Promise.resolve()
            }

            const formData = new FormData()
            formData.append('_token', this.csrfToken)

            if (useBeacon && navigator.sendBeacon) {
                navigator.sendBeacon(this.releaseUrl, formData)

                return Promise.resolve()
            }

            return window
                .fetch(this.releaseUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    keepalive: true,
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                    },
                    body: formData,
                })
                .catch(() => {})
        },

        stop() {
            if (this.timer === null) {
                return
            }

            window.clearInterval(this.timer)
            this.timer = null
        },
    }
}
