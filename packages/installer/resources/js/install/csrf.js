;(function (global) {
    'use strict'

    function createCsrf(options) {
        var form = options.form
        var csrfToken =
            (document.querySelector('meta[name="csrf-token"]') || {}).content ||
            ''

        function setToken(token) {
            if (!token) {
                return
            }

            csrfToken = token

            var meta = document.querySelector('meta[name="csrf-token"]')
            if (meta) {
                meta.setAttribute('content', token)
            }

            form.querySelectorAll('input[name="_token"]').forEach(
                function (input) {
                    input.value = token
                },
            )
        }

        function refresh() {
            return fetch(form.action, {
                method: 'GET',
                headers: {
                    Accept: 'text/html',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                cache: 'no-store',
            })
                .then(function (response) {
                    return response.text()
                })
                .then(function (html) {
                    var parsed = new DOMParser().parseFromString(
                        html,
                        'text/html',
                    )
                    var tokenMeta = parsed.querySelector(
                        'meta[name="csrf-token"]',
                    )
                    var token = tokenMeta
                        ? tokenMeta.getAttribute('content')
                        : ''

                    setToken(token)

                    return token
                })
        }

        return {
            token: function () {
                return csrfToken
            },
            setToken: setToken,
            refresh: refresh,
        }
    }

    global.CapellInstaller = global.CapellInstaller || {}
    global.CapellInstaller.createCsrf = createCsrf
})(typeof globalThis !== 'undefined' ? globalThis : window)
