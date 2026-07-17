;(function (global) {
    'use strict'

    var installer = global.CapellInstaller || {}
    var form = document.getElementById('install-form')

    if (!form) {
        return
    }

    var configElement = document.getElementById('capell-installer-config')
    var config = configElement
        ? JSON.parse(configElement.textContent || '{}')
        : {}
    var messages = config.messages || {}
    var submitButton = document.getElementById('submit-button')
    var wizard = installer.createWizard({ form: form })
    var packages = installer.createPackageSelection({
        config: config,
        messages: messages,
        submitButton: submitButton,
    })
    var formOptions = installer.createFormOptions({
        messages: messages,
        packages: packages,
    })
    var progress = installer.createProgress({ messages: messages })
    var csrf = installer.createCsrf({ form: form })
    var runner = installer.createInstallRunner({
        form: form,
        wizard: wizard,
        packages: packages,
        progress: progress,
        csrf: csrf,
        messages: messages,
    })
    var reportLink = document.getElementById('report-link')
    var reportDownloadButton = document.querySelector(
        '[data-report-download-button]',
    )
    var failureRetryButton = document.getElementById('failure-retry-button')

    packages.onSelectionChanged(formOptions.updateAdminPanelChangesVisibility)

    if (reportLink) {
        reportLink.addEventListener('click', function (event) {
            event.stopPropagation()
        })
    }

    if (reportDownloadButton) {
        reportDownloadButton.addEventListener('click', function (event) {
            event.stopPropagation()
        })
    }

    if (failureRetryButton) {
        failureRetryButton.addEventListener('click', function () {
            progress.backLink.click()
        })
    }

    formOptions.update()
    packages.update()

    form.addEventListener('submit', function (event) {
        event.preventDefault()
        wizard.clearFieldErrors()

        if (!wizard.isOnLastStep()) {
            wizard.continueToNext()
            return
        }

        if (wizard.validateAll()) {
            runner.start()
        }
    })

    form.addEventListener('keydown', function (event) {
        if (
            event.key !== 'Enter' ||
            wizard.isOnLastStep() ||
            event.target.tagName === 'TEXTAREA'
        ) {
            return
        }

        event.preventDefault()
        wizard.clearFieldErrors()
        wizard.continueToNext()
    })
})(typeof globalThis !== 'undefined' ? globalThis : window)
