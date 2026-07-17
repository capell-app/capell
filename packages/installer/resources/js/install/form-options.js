;(function (global) {
    'use strict'

    // Standalone form option toggles: admin user mode, admin panel changes,
    // custom language fields, developer tooling, and the execution summary.
    function createFormOptions(options) {
        var messages = options.messages
        var packages = options.packages

        var adminUserModeInputs = document.querySelectorAll(
            '[data-admin-user-mode]',
        )
        var adminUserFieldsets = document.querySelectorAll(
            '[data-admin-user-fields]',
        )
        var adminPanelChanges = document.querySelector(
            '[data-admin-panel-changes]',
        )
        var adminPanelChangesModeInputs = document.querySelectorAll(
            '[data-admin-panel-changes-mode]',
        )
        var adminPanelManualHelp = document.querySelector(
            '[data-admin-panel-manual-help]',
        )
        var languageSelect = document.querySelector('[data-language-select]')
        var customLanguageFields = document.querySelector(
            '[data-custom-language-fields]',
        )
        var developerToolingCheckbox = document.querySelector(
            '[data-developer-tooling-checkbox]',
        )
        var boostToolingOptions = document.querySelector(
            '[data-boost-tooling-options]',
        )
        var boostToolingCheckbox = document.querySelector(
            '[data-boost-tooling-checkbox]',
        )
        var summaryAdminMode = document.querySelector(
            '[data-summary-admin-mode]',
        )
        var summaryExecution = document.querySelector(
            '[data-summary-execution]',
        )
        var runAsJobCheckbox = document.querySelector(
            'input[name="run_as_job"]',
        )

        var createAdminSummary = messages.createAdminSummary || ''
        var existingAdminSummary = messages.existingAdminSummary || ''
        var backgroundJobSummary = messages.backgroundJobSummary || ''
        var directExecutionSummary = messages.directExecutionSummary || ''

        function selectedAdminUserMode() {
            var checked = document.querySelector(
                '[data-admin-user-mode]:checked',
            )

            return checked ? checked.value : 'create'
        }

        function selectedAdminPanelChangesMode() {
            var checked = document.querySelector(
                '[data-admin-panel-changes-mode]:checked',
            )

            return checked ? checked.value : 'auto'
        }

        function setFieldsetRequired(fieldset, required) {
            fieldset
                .querySelectorAll('input, select')
                .forEach(function (input) {
                    input.required = required
                })
        }

        function updateAdminUserFields() {
            var mode = selectedAdminUserMode()

            adminUserFieldsets.forEach(function (fieldset) {
                var active = fieldset.dataset.adminUserFields === mode
                fieldset.classList.toggle('hidden', !active)
                setFieldsetRequired(fieldset, active)
            })

            if (summaryAdminMode) {
                summaryAdminMode.textContent =
                    mode === 'existing'
                        ? existingAdminSummary
                        : createAdminSummary
            }
        }

        function updateAdminPanelChangesMode() {
            var autoSelected = selectedAdminPanelChangesMode() === 'auto'

            if (adminPanelManualHelp) {
                adminPanelManualHelp.classList.toggle('hidden', autoSelected)
            }
        }

        function updateAdminPanelChangesVisibility() {
            if (!adminPanelChanges) {
                return
            }

            var hasAdminPackage = packages.isChecked(
                adminPanelChanges.dataset.adminPackageName,
            )

            adminPanelChanges.classList.toggle('hidden', !hasAdminPackage)
            updateAdminPanelChangesMode()
        }

        function updateCustomLanguageFields() {
            if (!languageSelect || !customLanguageFields) {
                return
            }

            var isCustomLanguage = languageSelect.value === '__custom'

            customLanguageFields.classList.toggle('hidden', !isCustomLanguage)
            setFieldsetRequired(customLanguageFields, isCustomLanguage)
        }

        function updateDeveloperToolingFields() {
            if (!boostToolingCheckbox) {
                return
            }

            var developerToolingSelected =
                developerToolingCheckbox && developerToolingCheckbox.checked

            boostToolingCheckbox.disabled = !developerToolingSelected

            if (boostToolingOptions) {
                boostToolingOptions.classList.toggle(
                    'hidden',
                    !developerToolingSelected,
                )
            }
        }

        function updateExecutionSummary() {
            if (!summaryExecution) {
                return
            }

            summaryExecution.textContent =
                runAsJobCheckbox && runAsJobCheckbox.checked
                    ? backgroundJobSummary
                    : directExecutionSummary
        }

        adminUserModeInputs.forEach(function (input) {
            input.addEventListener('change', updateAdminUserFields)
        })

        adminPanelChangesModeInputs.forEach(function (input) {
            input.addEventListener('change', updateAdminPanelChangesMode)
        })

        if (developerToolingCheckbox) {
            developerToolingCheckbox.addEventListener(
                'change',
                updateDeveloperToolingFields,
            )
        }

        if (languageSelect) {
            languageSelect.addEventListener(
                'change',
                updateCustomLanguageFields,
            )
        }

        if (runAsJobCheckbox) {
            runAsJobCheckbox.addEventListener('change', updateExecutionSummary)
        }

        return {
            update: function () {
                updateAdminUserFields()
                updateAdminPanelChangesMode()
                updateAdminPanelChangesVisibility()
                updateCustomLanguageFields()
                updateDeveloperToolingFields()
                updateExecutionSummary()
            },
            updateAdminPanelChangesVisibility:
                updateAdminPanelChangesVisibility,
        }
    }

    global.CapellInstaller = global.CapellInstaller || {}
    global.CapellInstaller.createFormOptions = createFormOptions
})(typeof globalThis !== 'undefined' ? globalThis : window)
