export default function capellContentLockHeartbeat(config) {
    return {
        heartbeatUrl: config.heartbeatUrl,
        releaseUrl: config.releaseUrl,
        csrfToken: config.csrfToken,
        intervalMs: config.intervalMs ?? 30000,
        timer: null,
        released: false,

        init() {
            this.heartbeat()

            this.timer = window.setInterval(() => {
                this.heartbeat()
            }, this.intervalMs)

            window.addEventListener('pagehide', () => {
                this.release(true)
            })

            window.addEventListener('beforeunload', () => {
                this.release(true)
            })
        },

        heartbeat() {
            if (this.released) {
                return
            }

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
                    if (response.status === 409 || response.status === 403) {
                        this.stop()
                    }
                })
                .catch(() => {})
        },

        release(useBeacon = false) {
            if (this.released) {
                return
            }

            this.released = true
            this.stop()

            const formData = new FormData()
            formData.append('_token', this.csrfToken)

            if (useBeacon && navigator.sendBeacon) {
                navigator.sendBeacon(this.releaseUrl, formData)

                return
            }

            window
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
