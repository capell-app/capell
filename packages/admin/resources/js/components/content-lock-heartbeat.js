export default function capellContentLockHeartbeat(config) {
    return {
        heartbeatUrl: config.heartbeatUrl ?? '',
        releaseUrl: config.releaseUrl ?? '',
        logoutUrl: config.logoutUrl ?? '',
        csrfToken: config.csrfToken ?? '',
        intervalMs: config.intervalMs ?? 30000,
        localDraftStorageKey: config.storageKey ?? '',
        localDraftSession: config.localDraftSession ?? '',
        localDraftRestoreConfirm: config.localDraftRestoreConfirm ?? '',
        localDraftDiscardConfirm: config.localDraftDiscardConfirm ?? '',
        localDraftUnavailableMessage: config.localDraftUnavailableMessage ?? '',
        localDraftUnavailable: false,
        localDraftChanged: false,
        localDraftChangedMessage: config.localDraftChangedMessage ?? '',
        localDraftGeneration: 0,
        recoveredDraftKey: null,
        recoveredDraftRaw: null,
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
        lastDurableDataHash: null,
        saveSubmissions: [],
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
            this.initialDataHash = this.dataHash()
            this.lastDurableDataHash = this.initialDataHash
            this.localDraft = this.readStoredLocalDraft()
            this.localDraftAvailable = this.localDraft !== null
            this.cleanupExpiredLocalDrafts()
            this.applyReadOnly()

            if (typeof this.$wire?.$hook === 'function') {
                this.$wire.$hook('commit', ({ commit, succeed, fail }) => {
                    const calls = Array.isArray(commit?.calls)
                        ? commit.calls
                        : []
                    const isSave = calls.some((call) => this.isSaveCall(call))

                    if (isSave) {
                        const submission = this.captureSaveSubmission()
                        this.saveSubmissions.push(submission)
                        const forget = () => {
                            this.saveSubmissions = this.saveSubmissions.filter(
                                (entry) => entry !== submission,
                            )
                        }
                        succeed(forget)
                        if (typeof fail === 'function') fail(forget)
                        return
                    }

                    succeed(() => {
                        this.queueLocalDraft()
                        this.heartbeat()
                    })
                })
            }

            this.$nextTick(() => {
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

            const queueDraft = () => this.queueLocalDraft()

            this.form.addEventListener('input', queueDraft)
            this.form.addEventListener('change', queueDraft)

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

        queueLocalDraft() {
            if (this.readOnly || this.released) {
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

        getLocalStorage() {
            try {
                const storage = window.localStorage ?? null
                if (storage === null) this.localDraftUnavailable = true
                return storage
            } catch {
                this.localDraftUnavailable = true
                return null
            }
        },

        async withDraftLock(callback) {
            if (!window.navigator?.locks?.request) {
                this.localDraftUnavailable = true
                return false
            }
            try {
                return await window.navigator.locks.request(
                    `capell-draft:${this.localDraftStorageKey}`,
                    callback,
                )
            } catch {
                this.localDraftUnavailable = true
                return false
            }
        },

        async persistLocalDraft() {
            const data = this.currentData()
            const key = this.currentDraftKey()
            const generation = this.localDraftGeneration
            if (key === '' || !this.getLocalStorage()) return
            await this.withDraftLock(() => {
                if (generation !== this.localDraftGeneration) return false
                const storage = this.getLocalStorage()
                if (storage === null) return false
                if (
                    data === null ||
                    this.dataHash(data) === this.initialDataHash
                ) {
                    storage.removeItem(key)
                } else {
                    storage.setItem(
                        key,
                        JSON.stringify({
                            version: this.localDraftVersion,
                            data,
                            savedAt: Date.now(),
                            revision: window.crypto.randomUUID(),
                        }),
                    )
                }
                this.lastDurableDataHash = this.dataHash(data)
                return true
            })
        },

        currentDraftKey() {
            if (this.localDraftStorageKey === '') return ''
            return this.localDraftSession === ''
                ? this.localDraftStorageKey
                : `${this.localDraftStorageKey}:${this.localDraftSession}`
        },

        async cleanupExpiredLocalDrafts() {
            if (this.localDraftStorageKey === '' || !this.getLocalStorage())
                return
            await this.withDraftLock(() => {
                const storage = this.getLocalStorage()
                const prefix = `${this.localDraftStorageKey}:`
                const keys = Array.from(
                    { length: storage.length },
                    (_, index) => storage.key(index),
                )
                for (const key of keys) {
                    if (!(
                        key === this.localDraftStorageKey ||
                        key?.startsWith(prefix)
                    ))
                        continue
                    try {
                        const draft = JSON.parse(storage.getItem(key))
                        if (
                            typeof draft?.savedAt === 'number' &&
                            Number.isFinite(draft.savedAt) &&
                            Date.now() - draft.savedAt > this.localDraftTtlMs
                        )
                            storage.removeItem(key)
                    } catch {
                        // Leave malformed entries for manual cleanup.
                    }
                }
                return true
            })
        },

        readStoredLocalDraft() {
            if (this.localDraftStorageKey === '') return null
            try {
                const storage = this.getLocalStorage()
                if (!storage) return null
                const prefix = `${this.localDraftStorageKey}:`
                const candidates = []
                for (let index = 0; index < storage.length; index++) {
                    const key = storage.key(index)
                    if (
                        key === this.currentDraftKey() ||
                        !(
                            key === this.localDraftStorageKey ||
                            key?.startsWith(prefix)
                        )
                    )
                        continue
                    const raw = storage.getItem(key)
                    let draft
                    try {
                        draft = JSON.parse(raw)
                    } catch {
                        continue
                    }
                    if (
                        !draft ||
                        draft.version !== this.localDraftVersion ||
                        typeof draft.data !== 'object' ||
                        draft.data === null ||
                        typeof draft.savedAt !== 'number' ||
                        !Number.isFinite(draft.savedAt) ||
                        draft.savedAt > Date.now() ||
                        Date.now() - draft.savedAt > this.localDraftTtlMs
                    )
                        continue
                    candidates.push({ key, raw, draft })
                }
                candidates.sort(
                    (left, right) =>
                        left.draft.savedAt - right.draft.savedAt ||
                        left.key.localeCompare(right.key),
                )
                if (candidates.length === 0) return null
                this.recoveredDraftKey = candidates[0].key
                this.recoveredDraftRaw = candidates[0].raw
                return candidates[0].draft.data
            } catch {
                return null
            }
        },

        async restoreLocalDraft() {
            if (
                this.readOnly ||
                this.localDraft === null ||
                typeof this.$wire?.$set !== 'function'
            )
                return
            if (
                this.dataHash() !== this.initialDataHash &&
                !window.confirm(this.localDraftRestoreConfirm)
            )
                return
            const data = this.clone(this.localDraft)
            if (data === null) return
            const current = await this.withDraftLock(
                () =>
                    this.recoveredDraftKey !== null &&
                    this.getLocalStorage()?.getItem(this.recoveredDraftKey) ===
                        this.recoveredDraftRaw,
            )
            if (!current) {
                if (!this.localDraftUnavailable) {
                    this.refreshRecoveredDraft()
                    this.localDraftChanged = true
                }
                return
            }
            this.$wire.$set('data', data)
            this.$nextTick(() => this.queueLocalDraft())
        },

        async discardLocalDraft() {
            if (!window.confirm(this.localDraftDiscardConfirm)) return
            if (await this.removeRecoveredDraft()) {
                this.refreshRecoveredDraft()
            } else if (!this.localDraftUnavailable) {
                this.refreshRecoveredDraft()
                this.localDraftChanged = true
            }
        },

        async removeRecoveredDraft() {
            return this.withDraftLock(() => {
                if (
                    this.recoveredDraftKey === null ||
                    this.getLocalStorage()?.getItem(this.recoveredDraftKey) !==
                        this.recoveredDraftRaw
                )
                    return false
                this.getLocalStorage()?.removeItem(this.recoveredDraftKey)
                return true
            })
        },

        refreshRecoveredDraft() {
            this.recoveredDraftKey = null
            this.recoveredDraftRaw = null
            this.localDraft = this.readStoredLocalDraft()
            this.localDraftAvailable = this.localDraft !== null
        },

        changeDraftSession(context) {
            if (
                typeof context !== 'string' ||
                context === '' ||
                context === this.localDraftSession
            )
                return
            this.localDraftGeneration++
            if (this.localDraftTimer !== null) {
                window.clearTimeout(this.localDraftTimer)
                this.localDraftTimer = null
            }
            this.localDraftSession = context
            this.lastDurableDataHash = null
            this.refreshRecoveredDraft()
            this.queueLocalDraft()
        },

        isSaveCall(call) {
            const names = ['saveAsDraft', 'saveAsDraftWithLocation']
            if (call?.method === 'save' || names.includes(call?.method))
                return true
            if (call?.method === 'mountAction')
                return names.includes(call?.params?.[0])
            if (call?.method === 'callMountedAction') {
                const actions = this.$wire?.mountedActions
                return (
                    Array.isArray(actions) &&
                    names.includes(actions.at(-1)?.name)
                )
            }
            return false
        },

        captureSaveSubmission() {
            const key = this.currentDraftKey()
            let raw = null
            try {
                raw = this.getLocalStorage()?.getItem(key) ?? null
            } catch {
                this.localDraftUnavailable = true
            }
            return { key, raw, hash: this.dataHash() }
        },

        async markEditorSaved(acknowledgedData) {
            const acknowledged = this.clone(acknowledgedData)
            if (acknowledged === null || typeof acknowledged !== 'object') {
                this.queueLocalDraft()
                return
            }

            this.initialDataHash = this.dataHash(acknowledged)
            this.localDraftGeneration++
            if (this.localDraftTimer !== null) {
                window.clearTimeout(this.localDraftTimer)
                this.localDraftTimer = null
            }
            const currentHash = this.dataHash()
            if (currentHash !== this.initialDataHash) {
                this.queueLocalDraft()
                return
            }

            this.lastDurableDataHash = this.initialDataHash
            const matching = this.saveSubmissions.filter(
                (entry) =>
                    entry.key === this.currentDraftKey() &&
                    entry.hash === this.initialDataHash,
            )
            if (matching.length === 0) return
            const observed = matching[0].raw
            if (
                observed === null ||
                matching.some((entry) => entry.raw !== observed)
            )
                return
            await this.withDraftLock(() => {
                const storage = this.getLocalStorage()
                if (storage === null) return false
                if (storage.getItem(this.currentDraftKey()) === observed) {
                    storage.removeItem(this.currentDraftKey())
                }
                return true
            })
        },

        currentData() {
            if (typeof this.$wire?.data === 'undefined') {
                return null
            }

            return this.clone(this.$wire.data)
        },

        dataHash(data = this.currentData()) {
            return data === null ? null : JSON.stringify(data)
        },

        clone(data) {
            try {
                return JSON.parse(JSON.stringify(data))
            } catch {
                return null
            }
        },

        saveEmergencyDraft() {
            const storage = this.getLocalStorage()
            const data = this.currentData()
            if (
                storage === null ||
                this.localDraftStorageKey === '' ||
                data === null ||
                this.dataHash(data) === this.initialDataHash
            )
                return

            try {
                const revision = window.crypto.randomUUID()
                const key = `${this.localDraftStorageKey}:emergency:${revision}`
                if (storage.getItem(key) !== null) {
                    this.localDraftUnavailable = true
                    return
                }
                // Each emergency key is immutable; other sessions never write to it.
                storage.setItem(
                    key,
                    JSON.stringify({
                        version: this.localDraftVersion,
                        data,
                        savedAt: Date.now(),
                        revision,
                    }),
                )
            } catch {
                this.localDraftUnavailable = true
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
            if (this.dataHash() !== this.lastDurableDataHash) {
                this.saveEmergencyDraft()
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
