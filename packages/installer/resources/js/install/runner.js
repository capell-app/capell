;(function (global) {
    'use strict'

    function createInstallRunner(options) {
        var form = options.form
        var wizard = options.wizard
        var packages = options.packages
        var progress = options.progress
        var csrf = options.csrf
        var messages = options.messages || {}
        var submitButton = document.getElementById('submit-button')
        var activeSuccessUrl = ''

        function setSubmitting(value) {
            submitButton.classList.toggle('is-loading', value)
            submitButton.disabled = value
            if (value) submitButton.setAttribute('aria-busy', 'true')
            else submitButton.removeAttribute('aria-busy')
            packages.updateSubmitButtonLabel(value)
        }

        function responseResult(response) {
            return response.text().then(function (text) {
                var payload = {}
                try {
                    payload = text ? JSON.parse(text) : {}
                } catch (error) {
                    payload = {
                        status: 'failed',
                        error: global.CapellInstaller.support.responseLooksLikeServerTimeout(
                            response.status,
                            text,
                        )
                            ? messages.serverTimeoutError ||
                              messages.unknownError
                            : 'HTTP ' +
                              response.status +
                              ' ' +
                              (response.statusText || ''),
                    }
                }
                return { httpStatus: response.status, payload: payload }
            })
        }

        function failStep(stepKey, showReport) {
            progress.setStatus('failed')
            progress.finishStepTimer(stepKey)
            progress.markStepStatus(null)
            progress.showFailurePanel(stepKey)
            progress.backLink.hidden = false
            if (showReport)
                progress.configureReport(
                    document.getElementById('report-link').action,
                    '',
                )
        }

        function runNextStep(installId, runStepUrl, stepKey) {
            if (!stepKey) {
                return
            }

            progress.setStatus('running')
            progress.startStepTimer(stepKey)
            progress.markStepStatus(stepKey)

            fetch(runStepUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf.token(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ install_id: installId, step: stepKey }),
                credentials: 'same-origin',
            })
                .then(responseResult)
                .then(function (result) {
                    if (result.payload.csrfToken) {
                        csrf.setToken(result.payload.csrfToken)
                    }

                    if (result.httpStatus === 419) {
                        failStep(stepKey, false)
                        progress.appendLogLine(
                            '✗ ' + messages.sessionExpired,
                            'error',
                        )
                        return
                    }
                    if (result.payload.redirectUrl) {
                        window.location.href = result.payload.redirectUrl
                        return
                    }
                    progress.renderLines(result.payload.lines || [])
                    if (result.payload.plan) {
                        progress.renderPlanSteps(result.payload.plan)
                    }
                    if (
                        result.payload.status === 'failed' ||
                        result.httpStatus >= 400
                    ) {
                        if (result.payload.error)
                            progress.appendLogLine(
                                '✗ ' +
                                    (result.payload.errorClass
                                        ? result.payload.errorClass + ': '
                                        : '') +
                                    result.payload.error,
                                'error',
                            )
                        if (result.payload.remediation)
                            progress.appendLogLine(
                                'Fix: ' + result.payload.remediation,
                                'error',
                            )
                        failStep(stepKey, true)
                        return
                    }
                    progress.completeStep(stepKey)
                    if (
                        result.payload.status === 'complete' ||
                        !result.payload.nextStep
                    ) {
                        if (activeSuccessUrl) {
                            window.location.href = activeSuccessUrl
                            return
                        }
                        progress.setStatus('complete')
                        progress.markStepStatus(null)
                        wizard.completeInstallingFlow()
                        progress.showInstalledPanel()
                        return
                    }
                    runNextStep(installId, runStepUrl, result.payload.nextStep)
                })
                .catch(function (error) {
                    console.error(
                        '[Capell Setup] Network error on step "' +
                            stepKey +
                            '":',
                        error,
                    )
                    progress.appendLogLine(
                        'Network error while running "' +
                            stepKey +
                            '". Retrying…',
                        'error',
                    )
                    setTimeout(function () {
                        runNextStep(installId, runStepUrl, stepKey)
                    }, 2000)
                })
        }

        function returnToForm(message) {
            progress.showFormView()
            wizard.setFlowStep(wizard.currentStep())
            setSubmitting(false)
            if (message) wizard.showGlobalError(message)
        }

        function submitInstallForm(hasRetried) {
            var formData = new FormData(form)
            formData.set('_token', csrf.token())
            return fetch(form.action, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf.token(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: formData,
                credentials: 'same-origin',
            })
                .then(responseResult)
                .then(function (result) {
                    if (result.payload.csrfToken) {
                        csrf.setToken(result.payload.csrfToken)
                    }

                    if (result.httpStatus === 419) {
                        if (!hasRetried)
                            return csrf.refresh().then(function (token) {
                                if (!token) {
                                    returnToForm(messages.sessionExpired)
                                    return
                                }

                                return submitInstallForm(true)
                            })
                        returnToForm(messages.sessionExpired)
                        return
                    }
                    if (result.httpStatus === 422) {
                        if (
                            result.payload.errors &&
                            result.payload.errors.installation_confirmed &&
                            reviewToken
                        ) {
                            reviewToken.value = ''
                            reviewAccepted.checked = false
                            reviewPanel.hidden = true
                        }
                        progress.showFormView()
                        wizard.setFlowStep(wizard.currentStep())
                        wizard.showFieldErrors(result.payload.errors || {})
                        setSubmitting(false)
                        return
                    }
                    if (
                        result.httpStatus >= 200 &&
                        result.httpStatus < 300 &&
                        result.payload.redirectUrl
                    ) {
                        window.location.href = result.payload.redirectUrl
                        return
                    }
                    if (
                        result.httpStatus >= 200 &&
                        result.httpStatus < 300 &&
                        result.payload.installId &&
                        result.payload.runStepUrl
                    ) {
                        activeSuccessUrl = result.payload.successUrl || ''
                        progress.renderPlanSteps(result.payload.plan || [])
                        progress.configureReport(
                            result.payload.reportUrl,
                            result.payload.installId,
                        )
                        if (result.payload.logPath)
                            progress.appendLogLine(
                                'Log file: ' + result.payload.logPath,
                                'empty',
                            )
                        runNextStep(
                            result.payload.installId,
                            result.payload.runStepUrl,
                            result.payload.nextStep,
                        )
                        return
                    }
                    returnToForm(
                        result.payload.error ||
                            result.payload.message ||
                            messages.unknownError,
                    )
                })
                .catch(function (error) {
                    console.error(
                        '[Capell Setup] Network error on form submit:',
                        error,
                    )
                    returnToForm(messages.networkError)
                })
        }

        var reviewRevision = 0
        var reviewPanel = document.getElementById('installation-review')
        var reviewToken = form.querySelector('[name="review_token"]')
        var reviewAccepted = form.querySelector('[data-installation-confirmed]')

        function invalidateReview(event) {
            if (
                !reviewPanel ||
                event.target === reviewAccepted ||
                event.target === reviewToken
            )
                return
            reviewRevision += 1
            reviewToken.value = ''
            reviewAccepted.checked = false
            reviewPanel.hidden = true
            packages.updateSubmitButtonLabel(false)
        }
        form.addEventListener('change', invalidateReview)
        form.addEventListener('input', invalidateReview)

        function review() {
            var revision = reviewRevision
            setSubmitting(true)
            submitButton.querySelector('[data-submit-label]').textContent =
                messages.reviewLoading || messages.reviewButton
            var formData = new FormData(form)
            formData.set('review_only', '1')
            formData.set('_token', csrf.token())
            return csrf
                .refresh()
                .catch(function () {
                    return null
                })
                .then(function () {
                    formData.set('_token', csrf.token())
                    return fetch(form.action, {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': csrf.token(),
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: formData,
                        credentials: 'same-origin',
                    })
                })
                .then(responseResult)
                .then(function (result) {
                    setSubmitting(false)
                    if (revision !== reviewRevision) {
                        wizard.showGlobalError(messages.reviewChanged)
                        return
                    }
                    if (result.httpStatus === 419) {
                        return csrf.refresh().then(function () {
                            wizard.showGlobalError(messages.sessionExpired)
                        })
                    }
                    if (result.httpStatus !== 200 || !result.payload.review) {
                        wizard.showFieldErrors(result.payload.errors || {})
                        wizard.showGlobalError(
                            result.payload.message || messages.unknownError,
                        )
                        return
                    }
                    var list = reviewPanel.querySelector(
                        '[data-installation-review-items]',
                    )
                    list.replaceChildren()
                    Object.keys(result.payload.review.items).forEach(
                        function (label) {
                            var term = document.createElement('dt')
                            term.textContent = label
                            var detail = document.createElement('dd')
                            detail.textContent =
                                result.payload.review.items[label]
                            list.appendChild(term)
                            list.appendChild(detail)
                        },
                    )
                    reviewToken.value = result.payload.review.token
                    reviewAccepted.checked = false
                    reviewPanel.hidden = false
                    reviewPanel.focus()
                    reviewPanel.scrollIntoView({
                        block: 'start',
                        behavior: 'smooth',
                    })
                    submitButton.querySelector(
                        '[data-submit-label]',
                    ).textContent = messages.reviewInstallButton
                })
                .catch(function () {
                    setSubmitting(false)
                    wizard.showGlobalError(messages.networkError)
                })
        }

        function start() {
            if (reviewPanel && !reviewToken.value) {
                return review()
            }
            if (reviewPanel && !reviewAccepted.checked) {
                wizard.showGlobalError(messages.reviewRequired)
                reviewAccepted.focus()
                return
            }
            setSubmitting(true)
            wizard.beginInstallingFlow()
            progress.reset()
            progress.showProgressView()
            progress.setStatus('queued')
            csrf.refresh()
                .catch(function () {
                    return null
                })
                .then(function () {
                    submitInstallForm(false)
                })
        }

        return {
            runNextStep: runNextStep,
            submitInstallForm: submitInstallForm,
            setSubmitting: setSubmitting,
            start: start,
        }
    }

    global.CapellInstaller = global.CapellInstaller || {}
    global.CapellInstaller.createInstallRunner = createInstallRunner
})(typeof globalThis !== 'undefined' ? globalThis : window)
