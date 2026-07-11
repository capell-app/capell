;(function () {
    var form = document.getElementById('install-form')
    var formView = document.getElementById('form-view')
    var progressView = document.getElementById('progress-view')
    var progressStatus = document.getElementById('progress-status')
    var progressStatusLabel = document.getElementById('progress-status-label')
    var currentStepStrip = document.getElementById('current-step-strip')
    var currentStepName = document.getElementById('current-step-name')
    var progressLoader = document.getElementById('progress-loader')
    var failurePanel = document.getElementById('failure-panel')
    var failureTitle = document.getElementById('failure-title')
    var failureMessage = document.getElementById('failure-message')
    var failureRetryButton = document.getElementById('failure-retry-button')
    var technicalLogPanel = document.getElementById('technical-log-panel')
    var progressStepsEl = document.getElementById('progress-steps')
    var logEl = document.getElementById('log')
    var submitButton = document.getElementById('submit-button')
    var errorsBox = document.getElementById('errors')
    var errorsList = document.getElementById('errors-list')
    var adminLink = document.getElementById('admin-link')
    var backLink = document.getElementById('back-link')
    var reportLink = document.getElementById('report-link')
    var reportDownloadButton = document.querySelector(
        '[data-report-download-button]',
    )

    var configElement = document.getElementById('capell-installer-config')
    var config = configElement
        ? JSON.parse(configElement.textContent || '{}')
        : {}
    var messages = config.messages || {}
    var statusLabels = messages.statuses || {}
    var installationFailedHeading = messages.installationFailedHeading || ''
    var installationProblemMessage = messages.installationProblemMessage || ''
    var sessionExpiredMessage = messages.sessionExpired || ''
    var waitingForOutputMessage = messages.waitingForOutput || ''
    var unknownErrorMessage = messages.unknownError || ''
    var networkErrorMessage = messages.networkError || ''
    var serverTimeoutErrorMessage = messages.serverTimeoutError || ''
    var adminUserModeInputs = document.querySelectorAll(
        '[data-admin-user-mode]',
    )
    var adminUserFieldsets = document.querySelectorAll(
        '[data-admin-user-fields]',
    )
    var adminPanelChanges = document.querySelector('[data-admin-panel-changes]')
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
    var packageSelectionLists = document.querySelectorAll(
        '[data-package-selection-list]',
    )
    var packageSelectAllInputs = document.querySelectorAll(
        '[data-package-select-all]',
    )
    var marketplaceLaterNote = document.querySelector(
        '[data-marketplace-later-note]',
    )
    var summaryPackageCount = document.querySelector(
        '[data-summary-package-count]',
    )
    var summaryAdminMode = document.querySelector('[data-summary-admin-mode]')
    var summaryExecution = document.querySelector('[data-summary-execution]')
    var runAsJobCheckbox = document.querySelector('input[name="run_as_job"]')
    var stepTriggers = document.querySelectorAll('[data-step-trigger]')
    var stepSections = document.querySelectorAll('[data-installer-step]')
    var flowItems = document.querySelectorAll('[data-flow-step]')
    var backStepButton = document.querySelector('[data-step-back]')
    var continueStepButton = document.querySelector('[data-step-continue]')

    var labels = {
        queued: statusLabels.queued || '',
        running: statusLabels.running || '',
        complete: statusLabels.complete || '',
        failed: statusLabels.failed || '',
    }

    var aborted = false
    var doneSteps = []
    var planSteps = []
    var installerStepOrder = ['readiness', 'site', 'packages', 'options']
    var flowStepOrder = installerStepOrder.concat(['installing'])
    var currentInstallerStep = 'readiness'
    var completedInstallFlow = false
    var activeStepKey = null
    var activeStepStartedAt = null
    var stepDurations = {}
    var stepTimerInterval = null
    var activeSuccessUrl = ''

    var csrfToken =
        (document.querySelector('meta[name="csrf-token"]') || {}).content || ''
    var requirementsMap = config.requirementsMap || {}
    var themePackageNames = config.themePackageNames || []
    var installedThemeKeys = config.installedThemeKeys || []
    var requiredByTemplate = messages.requiredByPackages || ''
    var packageSelectAllLabel = messages.packageSelectAll || 'Select all'
    var packageUnselectAllLabel = messages.packageUnselectAll || 'Unselect all'
    var createAdminSummary = messages.createAdminSummary || ''
    var existingAdminSummary = messages.existingAdminSummary || ''
    var backgroundJobSummary = messages.backgroundJobSummary || ''
    var directExecutionSummary = messages.directExecutionSummary || ''
    var progressCompletedStepsTemplate =
        messages.progressCompletedSteps || '__completed__ of __total__ complete'
    var progressPreviousStepLabel = messages.progressPreviousStep || 'Completed'
    var progressCurrentStepLabel =
        messages.progressCurrentStep || 'Current step'
    var progressNextStepLabel = messages.progressNextStep || 'Next'
    var submitLabel = messages.submitLabel || 'Install Capell'
    var installPackageLabel =
        messages.installPackageLabel || 'Install __count__ package'
    var installPackagesLabel =
        messages.installPackagesLabel || 'Install __count__ packages'
    var installingPackageLabel =
        messages.installingPackageLabel || 'Installing __count__ package'
    var installingPackagesLabel =
        messages.installingPackagesLabel || 'Installing __count__ packages'
    var submitButtonLabel = submitButton
        ? submitButton.querySelector('[data-submit-label]')
        : null

    function selectedAdminUserMode() {
        var checked = document.querySelector('[data-admin-user-mode]:checked')

        return checked ? checked.value : 'create'
    }

    function selectedAdminPanelChangesMode() {
        var checked = document.querySelector(
            '[data-admin-panel-changes-mode]:checked',
        )

        return checked ? checked.value : 'auto'
    }

    function setFieldsetRequired(fieldset, required) {
        fieldset.querySelectorAll('input, select').forEach(function (input) {
            if (required) {
                input.required = true
            } else {
                input.required = false
            }
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
                mode === 'existing' ? existingAdminSummary : createAdminSummary
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

        var adminPackageName = adminPanelChanges.dataset.adminPackageName
        var adminPackageCheckbox = getCheckbox(adminPackageName)
        var hasAdminPackage =
            adminPackageCheckbox && adminPackageCheckbox.checked

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

    function getCheckbox(packageName) {
        return document.querySelector(
            '[data-package-checkbox="' + packageName + '"]',
        )
    }

    function getRequiredHiddenInput(packageName) {
        return document.querySelector(
            '[data-required-hidden="' + packageName + '"]',
        )
    }

    function ensureRequiredHiddenInput(checkbox, packageName) {
        if (getRequiredHiddenInput(packageName)) {
            return
        }

        var hidden = document.createElement('input')
        hidden.type = 'hidden'
        hidden.name = checkbox.name
        hidden.value = checkbox.value
        hidden.setAttribute('data-required-hidden', packageName)
        checkbox.insertAdjacentElement('afterend', hidden)
    }

    function removeRequiredHiddenInput(packageName) {
        var hidden = getRequiredHiddenInput(packageName)
        if (hidden) {
            hidden.remove()
        }
    }

    function getAllCheckedPackages() {
        var checked = []
        document
            .querySelectorAll('[data-package-checkbox]:checked')
            .forEach(function (input) {
                checked.push(input.value)
            })
        return checked
    }

    function selectedPackageCount() {
        return getAllCheckedPackages().length
    }

    function packageCountLabel(singularTemplate, pluralTemplate, count) {
        return (count === 1 ? singularTemplate : pluralTemplate).replace(
            '__count__',
            count,
        )
    }

    function submitButtonText(isSubmitting) {
        var packageCount = selectedPackageCount()

        if (packageCount < 1) {
            return submitLabel
        }

        return isSubmitting
            ? packageCountLabel(
                  installingPackageLabel,
                  installingPackagesLabel,
                  packageCount,
              )
            : packageCountLabel(
                  installPackageLabel,
                  installPackagesLabel,
                  packageCount,
              )
    }

    function updateSubmitButtonLabel(isSubmitting) {
        if (!submitButtonLabel) {
            return
        }

        submitButtonLabel.textContent = submitButtonText(isSubmitting)
    }

    function showPackageSelectionLists() {
        packageSelectionLists.forEach(function (section) {
            section.classList.remove('hidden')
        })
    }

    function resolveRequiredPackages(checkedNames) {
        var required = {}
        var queue = checkedNames.slice()
        while (queue.length > 0) {
            var current = queue.shift()
            var deps = requirementsMap[current] || []
            deps.forEach(function (dep) {
                if (!required[dep]) {
                    required[dep] = true
                    queue.push(dep)
                }
            })
        }

        return required
    }

    function resolveRequiredByPackages(checkedNames) {
        var requiredBy = {}
        var visited = {}
        var queue = checkedNames.map(function (packageName) {
            return {
                packageName: packageName,
                requiredBy: packageName,
            }
        })

        while (queue.length > 0) {
            var item = queue.shift()
            var visitKey = item.packageName + '|' + item.requiredBy

            if (visited[visitKey]) {
                continue
            }

            visited[visitKey] = true

            var deps = requirementsMap[item.packageName] || []

            deps.forEach(function (dep) {
                requiredBy[dep] = requiredBy[dep] || {}
                requiredBy[dep][item.requiredBy] = true

                queue.push({
                    packageName: dep,
                    requiredBy: item.requiredBy,
                })
            })
        }

        return requiredBy
    }

    function packageLabel(packageName) {
        var row = document.querySelector(
            '[data-package-row="' + packageName + '"]',
        )
        var label = row ? row.querySelector('strong') : null

        return label ? label.textContent.trim() : packageName
    }

    function requiredByText(packageName, requiredBy) {
        var requiringPackages = Object.keys(requiredBy[packageName] || {})

        if (requiringPackages.length === 0) {
            return ''
        }

        var labels = requiringPackages
            .filter(function (requiringPackageName) {
                return requiringPackageName !== packageName
            })
            .map(packageLabel)
            .sort()

        return labels.length > 0
            ? requiredByTemplate.replace(':packages', labels.join(', '))
            : ''
    }

    function updatePackageStates() {
        showPackageSelectionLists()

        var directlyChecked = getAllCheckedPackages()
        var required = resolveRequiredPackages(directlyChecked)
        var requiredBy = resolveRequiredByPackages(directlyChecked)

        Object.keys(requirementsMap).forEach(function (packageName) {
            var checkbox = getCheckbox(packageName)
            if (!checkbox) {
                return
            }
            var badge = document.querySelector(
                '[data-required-badge="' + packageName + '"]',
            )
            var row = document.querySelector(
                '[data-package-row="' + packageName + '"]',
            )

            if (required[packageName]) {
                checkbox.checked = true
                checkbox.disabled = true
                ensureRequiredHiddenInput(checkbox, packageName)
                if (badge) {
                    badge.textContent = requiredByText(packageName, requiredBy)
                    badge.style.display = 'block'
                }
                if (row) {
                    row.style.opacity = '0.75'
                    row.style.cursor = 'not-allowed'
                }
            } else {
                checkbox.disabled = false
                removeRequiredHiddenInput(packageName)
                if (badge) {
                    badge.textContent = ''
                    badge.style.display = 'none'
                }
                if (row) {
                    row.style.opacity = ''
                    row.style.cursor = ''
                }
            }
        })
        if (summaryPackageCount) {
            summaryPackageCount.textContent = selectedPackageCount()
        }
        updateSubmitButtonLabel(false)
        updateThemeSelector()
        updateAdminPanelChangesVisibility()
        updateMarketplaceLaterNote()
        updatePackageSelectAllStates()
    }

    function packageSelectAllCheckboxes(scope) {
        var selector =
            scope === 'core'
                ? '[data-package-checkbox][data-package-core="true"]'
                : '[data-package-checkbox][data-package-extension="true"]'

        return Array.prototype.slice.call(document.querySelectorAll(selector))
    }

    function updatePackageSelectAllStates() {
        packageSelectAllInputs.forEach(function (input) {
            var checkboxes = packageSelectAllCheckboxes(
                input.dataset.packageSelectAll,
            )

            if (checkboxes.length === 0) {
                input.checked = false
                input.indeterminate = false
                input.disabled = true
                return
            }

            var checkedCount = checkboxes.filter(function (checkbox) {
                return checkbox.checked
            }).length

            input.checked = checkedCount === checkboxes.length
            input.indeterminate =
                checkedCount > 0 && checkedCount < checkboxes.length
            input.disabled = false

            var label = input.parentElement
                ? input.parentElement.querySelector(
                      '[data-package-select-all-label]',
                  )
                : null

            if (label) {
                label.textContent = input.checked
                    ? packageUnselectAllLabel
                    : packageSelectAllLabel
            }
        })
    }

    function updateMarketplaceLaterNote() {
        if (!marketplaceLaterNote) {
            return
        }

        var marketplaceCheckbox = document.querySelector(
            '[data-package-checkbox="capell-app/marketplace"]',
        )

        marketplaceLaterNote.hidden = !(
            marketplaceCheckbox && marketplaceCheckbox.checked
        )
    }

    function selectedThemeKeys() {
        var checkedPackages = getAllCheckedPackages()
        var selectedKeys = installedThemeKeys.slice()

        Object.keys(themePackageNames).forEach(function (themeKey) {
            if (checkedPackages.indexOf(themePackageNames[themeKey]) !== -1) {
                selectedKeys.push(themeKey)
            }
        })

        return selectedKeys.filter(function (themeKey, index, keys) {
            return keys.indexOf(themeKey) === index
        })
    }

    function updateThemeSelector() {
        var section = document.querySelector('[data-theme-selector]')
        var options = document.querySelectorAll('[data-theme-option]')

        if (!section || options.length === 0) {
            return
        }

        var keys = selectedThemeKeys()
        var shouldShow = keys.length > 0

        section.classList.toggle('hidden', !shouldShow)
        var checkedAvailable = false

        options.forEach(function (option) {
            var available = keys.indexOf(option.value) !== -1
            var card = document.querySelector(
                '[data-theme-card="' + option.value + '"]',
            )

            option.disabled = !available

            if (card) {
                card.classList.toggle('hidden', !available)
            }

            if (available && option.checked) {
                checkedAvailable = true
            }
        })

        if (!checkedAvailable && keys.length > 0) {
            var fallbackKey =
                keys.indexOf('foundation') !== -1 ? 'foundation' : keys[0]
            var fallback = document.querySelector(
                '[data-theme-option][value="' + fallbackKey + '"]',
            )

            if (fallback) {
                fallback.checked = true
            }
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

    function lastInstallerStep() {
        return installerStepOrder[installerStepOrder.length - 1]
    }

    function installerStepTransitionDirection(stepName) {
        var currentStepIndex = installerStepOrder.indexOf(currentInstallerStep)
        var nextStepIndex = installerStepOrder.indexOf(stepName)

        if (currentStepIndex === -1 || nextStepIndex === -1) {
            return 'none'
        }

        if (nextStepIndex > currentStepIndex) {
            return 'forward'
        }

        if (nextStepIndex < currentStepIndex) {
            return 'back'
        }

        return 'none'
    }

    function setInstallerStep(stepName, transitionDirection) {
        var activeStepIndex = installerStepOrder.indexOf(stepName)
        var shouldAnimateStep =
            transitionDirection === 'forward' || transitionDirection === 'back'

        if (activeStepIndex === -1) {
            return
        }

        currentInstallerStep = stepName

        stepSections.forEach(function (section) {
            var isActiveSection = section.dataset.installerStep === stepName

            section.classList.remove(
                'installer-step-enter-forward',
                'installer-step-enter-back',
            )
            section.hidden = !isActiveSection

            if (!isActiveSection || !shouldAnimateStep) {
                return
            }

            section.classList.add(
                transitionDirection === 'back'
                    ? 'installer-step-enter-back'
                    : 'installer-step-enter-forward',
            )
        })

        stepTriggers.forEach(function (trigger) {
            var triggerStep = trigger.dataset.stepTrigger
            var isActive = triggerStep === stepName
            var triggerStepIndex = installerStepOrder.indexOf(triggerStep)

            trigger.classList.toggle('active', isActive)
            trigger.classList.toggle(
                'done',
                triggerStepIndex >= 0 && triggerStepIndex < activeStepIndex,
            )
            trigger.classList.toggle(
                'pending',
                triggerStepIndex > activeStepIndex,
            )

            if (trigger.tagName === 'BUTTON') {
                trigger.setAttribute(
                    'aria-selected',
                    isActive ? 'true' : 'false',
                )
            }
        })

        setFlowStep(stepName)

        if (backStepButton) {
            backStepButton.disabled = activeStepIndex === 0
        }

        if (continueStepButton && submitButton) {
            var isSubmitStep = activeStepIndex === installerStepOrder.length - 1
            continueStepButton.hidden = isSubmitStep
            submitButton.hidden = !isSubmitStep
        }

        if (window.matchMedia('(min-width: 901px)').matches) {
            window.scrollTo({
                top: 0,
                behavior: 'smooth',
            })
        }
    }

    function setFlowStep(stepName) {
        flowItems.forEach(function (item) {
            var itemStep = item.dataset.flowStep
            var itemStepIndex = flowStepOrder.indexOf(itemStep)
            var activeStepIndex = flowStepOrder.indexOf(stepName)
            var isActive = itemStep === stepName && !completedInstallFlow
            var isDone =
                completedInstallFlow &&
                itemStepIndex >= 0 &&
                itemStepIndex <= activeStepIndex

            item.classList.toggle('active', isActive)
            item.classList.toggle('done', isDone)
        })
    }

    function stepIndex(stepName) {
        return installerStepOrder.indexOf(stepName)
    }

    function isInputActive(input) {
        return (
            input &&
            !input.disabled &&
            input.type !== 'hidden' &&
            input.willValidate &&
            !input.closest('[hidden], .hidden')
        )
    }

    function findFieldNode(name) {
        var key = name.replace(/\.\d+$/, '').replace(/\[\]$/, '')
        var fieldNode = form.querySelector('[data-field="' + key + '"]')

        if (fieldNode) {
            return fieldNode
        }

        var input = Array.prototype.slice
            .call(form.elements)
            .filter(function (element) {
                return element.name === name || element.name === key
            })[0]

        return input ? input.closest('.field, [data-field]') : null
    }

    function validationErrorsForInputs(inputs) {
        var errors = {}

        inputs.forEach(function (input) {
            var key = input.name || input.id

            if (!key) {
                return
            }

            var fieldNode = findFieldNode(key)
            var label = fieldNode
                ? fieldNode.querySelector('.field-label, label')
                : null
            var labelText = label ? label.textContent.trim() : ''
            var summaryMessage = labelText
                ? labelText + ': ' + input.validationMessage
                : input.validationMessage

            errors[key] = [
                {
                    field: input.validationMessage,
                    summary: summaryMessage,
                },
            ]
        })

        return errors
    }

    function focusFirstInvalidInput(inputs) {
        if (inputs.length === 0) {
            return
        }

        inputs[0].scrollIntoView({
            block: 'center',
            behavior: 'smooth',
        })
        inputs[0].focus({ preventScroll: true })
    }

    function validateInstallerStep(stepName) {
        var sections = form.querySelectorAll(
            '[data-installer-step="' + stepName + '"]',
        )

        if (sections.length === 0) {
            return true
        }

        var invalidInputs = Array.prototype.slice
            .call(sections)
            .reduce(function (inputs, section) {
                return inputs.concat(
                    Array.prototype.slice.call(
                        section.querySelectorAll('input, select, textarea'),
                    ),
                )
            }, [])
            .filter(isInputActive)
            .filter(function (input) {
                return !input.checkValidity()
            })

        if (invalidInputs.length === 0) {
            clearFieldErrors()
            return true
        }

        showFieldErrors(validationErrorsForInputs(invalidInputs))
        focusFirstInvalidInput(invalidInputs)

        return false
    }

    function validateStepsUntil(targetStepName) {
        var targetStepIndex = stepIndex(targetStepName)
        var currentStepIndex = stepIndex(currentInstallerStep)

        if (
            targetStepIndex === -1 ||
            currentStepIndex === -1 ||
            targetStepIndex <= currentStepIndex
        ) {
            return true
        }

        for (
            var stepPosition = currentStepIndex;
            stepPosition < targetStepIndex;
            stepPosition += 1
        ) {
            var stepName = installerStepOrder[stepPosition]
            setInstallerStep(stepName, 'none')

            if (!validateInstallerStep(stepName)) {
                return false
            }
        }

        return true
    }

    function goToInstallerStep(stepName) {
        var transitionDirection = installerStepTransitionDirection(stepName)

        if (validateStepsUntil(stepName)) {
            setInstallerStep(stepName, transitionDirection)
        }
    }

    function continueToNextInstallerStep() {
        var nextStepIndex = installerStepOrder.indexOf(currentInstallerStep) + 1

        if (
            installerStepOrder[nextStepIndex] &&
            validateInstallerStep(currentInstallerStep)
        ) {
            setInstallerStep(installerStepOrder[nextStepIndex], 'forward')

            return true
        }

        return false
    }

    function validateAllInstallerSteps() {
        for (
            var stepPosition = 0;
            stepPosition < installerStepOrder.length;
            stepPosition += 1
        ) {
            var stepName = installerStepOrder[stepPosition]
            setInstallerStep(stepName, 'none')

            if (!validateInstallerStep(stepName)) {
                return false
            }
        }

        setInstallerStep(lastInstallerStep(), 'none')

        return true
    }

    stepTriggers.forEach(function (trigger) {
        trigger.addEventListener('click', function () {
            goToInstallerStep(trigger.dataset.stepTrigger)
        })

        trigger.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' && event.key !== ' ') {
                return
            }

            event.preventDefault()
            goToInstallerStep(trigger.dataset.stepTrigger)
        })
    })

    if (continueStepButton) {
        continueStepButton.addEventListener('click', function () {
            continueToNextInstallerStep()
        })
    }

    if (backStepButton) {
        backStepButton.addEventListener('click', function () {
            var previousStepIndex =
                installerStepOrder.indexOf(currentInstallerStep) - 1

            if (installerStepOrder[previousStepIndex]) {
                setInstallerStep(installerStepOrder[previousStepIndex], 'back')
            }
        })
    }

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

    document
        .querySelectorAll('[data-package-checkbox]')
        .forEach(function (input) {
            input.addEventListener('change', updatePackageStates)
        })

    packageSelectAllInputs.forEach(function (input) {
        input.addEventListener('change', function () {
            var checkboxes = packageSelectAllCheckboxes(
                input.dataset.packageSelectAll,
            )

            checkboxes.forEach(function (checkbox) {
                checkbox.checked = input.checked
            })

            updatePackageStates()
        })
    })

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
        languageSelect.addEventListener('change', updateCustomLanguageFields)
    }

    if (runAsJobCheckbox) {
        runAsJobCheckbox.addEventListener('change', updateExecutionSummary)
    }

    updateAdminUserFields()
    updatePackageStates()
    updateAdminPanelChangesMode()
    updateAdminPanelChangesVisibility()
    updateCustomLanguageFields()
    updateDeveloperToolingFields()
    updateExecutionSummary()
    setInstallerStep('readiness', 'none')

    if (failureRetryButton) {
        failureRetryButton.addEventListener('click', function () {
            backLink.click()
        })
    }

    function clearFieldErrors() {
        form.querySelectorAll('.field.has-error').forEach(function (node) {
            node.classList.remove('has-error')
            var msg = node.querySelector('.field-error')
            if (msg) {
                msg.textContent = ''
            }
        })
        form.querySelectorAll('[aria-invalid]').forEach(function (input) {
            input.removeAttribute('aria-invalid')
        })
        errorsBox.hidden = true
        errorsList.innerHTML = ''
    }

    function showFieldErrors(errors) {
        clearFieldErrors()
        Object.keys(errors).forEach(function (name) {
            var fieldNode = findFieldNode(name)
            var error = errors[name][0] || ''
            var message = typeof error === 'object' ? error.field : error
            if (fieldNode) {
                fieldNode.classList.add('has-error')
                var input = fieldNode.querySelector('input, select, textarea')
                if (input) {
                    input.setAttribute('aria-invalid', 'true')
                }
                var msgEl = fieldNode.querySelector('.field-error')
                if (msgEl) {
                    msgEl.textContent = message
                }
            }
        })
    }

    function setSubmitting(isSubmitting) {
        if (isSubmitting) {
            submitButton.classList.add('is-loading')
            submitButton.setAttribute('aria-busy', 'true')
            submitButton.disabled = true
        } else {
            submitButton.classList.remove('is-loading')
            submitButton.removeAttribute('aria-busy')
            submitButton.disabled = false
        }

        updateSubmitButtonLabel(isSubmitting)
    }

    function showProgressView() {
        formView.classList.add('hidden')
        progressView.classList.add('active')
    }

    function showInstalledPanel() {
        completedInstallFlow = true
        setFlowStep('installing')

        if (failurePanel) {
            failurePanel.hidden = true
        }
        progressStepsEl.hidden = true
        if (technicalLogPanel) {
            technicalLogPanel.hidden = true
        }
        reportLink.hidden = true
        if (reportDownloadButton) {
            reportDownloadButton.hidden = true
        }
        adminLink.hidden = true
        backLink.hidden = true
    }

    function setStatus(status) {
        progressStatus.classList.remove(
            'queued',
            'running',
            'complete',
            'failed',
        )
        progressStatus.classList.add(status)
        progressStatusLabel.textContent =
            labels[status] || status.charAt(0).toUpperCase() + status.slice(1)
        if (progressLoader) {
            progressLoader.hidden = status === 'complete' || status === 'failed'
        }
        if (
            currentStepStrip &&
            (status === 'complete' || status === 'failed')
        ) {
            currentStepStrip.hidden = true
        }
    }

    function renderPlanSteps(plan) {
        progressStepsEl.innerHTML = ''
        planSteps = plan || []

        var summary = document.createElement('div')
        summary.className = 'progress-steps-summary'

        var count = document.createElement('span')
        count.className = 'progress-steps-count'
        count.dataset.progressStepsCount = 'true'
        count.textContent = completedStepsLabel(0, planSteps.length)

        var track = document.createElement('span')
        track.className = 'progress-steps-track'
        track.setAttribute('aria-hidden', 'true')

        var fill = document.createElement('span')
        fill.className = 'progress-steps-fill'
        fill.dataset.progressStepsFill = 'true'
        track.appendChild(fill)

        summary.appendChild(count)
        summary.appendChild(track)
        progressStepsEl.appendChild(summary)

        var timeline = document.createElement('div')
        timeline.className = 'progress-steps-timeline'
        timeline.dataset.progressStepsTimeline = 'true'
        progressStepsEl.appendChild(timeline)

        renderStepWindow(null)
        updateProgressSummary()
    }

    function formatDuration(milliseconds) {
        var totalSeconds = Math.max(0, Math.round(milliseconds / 1000))
        var minutes = Math.floor(totalSeconds / 60)
        var seconds = totalSeconds % 60

        if (minutes > 0) {
            return minutes + 'm ' + String(seconds).padStart(2, '0') + 's'
        }

        return totalSeconds + 's'
    }

    function updateStepDurations() {
        progressStepsEl
            .querySelectorAll('[data-duration-for]')
            .forEach(function (node) {
                var key = node.dataset.durationFor
                if (stepDurations[key] !== undefined) {
                    node.textContent = formatDuration(stepDurations[key])
                    return
                }

                if (key === activeStepKey && activeStepStartedAt) {
                    node.textContent = formatDuration(
                        Date.now() - activeStepStartedAt,
                    )
                    return
                }

                node.textContent = ''
            })
    }

    function completedStepsLabel(completed, total) {
        return progressCompletedStepsTemplate
            .replace('__completed__', String(completed))
            .replace('__total__', String(total))
    }

    function stepByKey(stepKey) {
        return planSteps.find(function (step) {
            return step.key === stepKey
        })
    }

    function selectedStepWindowState() {
        var select = document.activeElement

        if (
            !select ||
            select.tagName !== 'SELECT' ||
            !select.closest('[data-step-window-state]')
        ) {
            return null
        }

        return {
            state: select.closest('[data-step-window-state]').dataset
                .stepWindowState,
            value: select.value,
        }
    }

    function restoreSelectedStepWindowState(state) {
        if (!state) {
            return
        }

        var row = progressStepsEl.querySelector(
            '[data-step-window-state="' + state.state + '"]',
        )
        var select = row ? row.querySelector('select') : null

        if (!select) {
            return
        }

        if (
            Array.prototype.some.call(select.options, function (option) {
                return option.value === state.value
            })
        ) {
            select.value = state.value
            updateSelectableStepWindowItem(row, state.value)
        }

        select.focus({ preventScroll: true })
    }

    function createStepWindowItem(step, state, metaLabel) {
        var row = document.createElement('div')
        row.className = 'progress-step ' + state
        row.dataset.stepKey = step.key

        var marker = document.createElement('span')
        marker.className = 'marker'

        var copy = document.createElement('span')
        copy.className = 'progress-step-copy'

        var meta = document.createElement('span')
        meta.className = 'meta'
        meta.textContent = metaLabel

        var label = document.createElement('span')
        label.className = 'label'
        label.textContent = step.label

        var duration = document.createElement('span')
        duration.className = 'duration'
        duration.dataset.durationFor = step.key

        copy.appendChild(meta)
        copy.appendChild(label)
        copy.appendChild(duration)
        row.appendChild(marker)
        row.appendChild(copy)

        return row
    }

    function createStepWindowOption(step) {
        var option = document.createElement('option')
        option.value = step.key
        option.textContent = step.label

        return option
    }

    function selectableStepWindowSize(steps) {
        return Math.min(Math.max(steps.length, 2), 6)
    }

    function updateSelectableStepWindowItem(row, stepKey) {
        var duration = row.querySelector('.duration')

        row.dataset.stepKey = stepKey

        if (duration) {
            duration.dataset.durationFor = stepKey
            duration.textContent =
                stepDurations[stepKey] !== undefined
                    ? formatDuration(stepDurations[stepKey])
                    : ''
        }
    }

    function createSelectableStepWindowItem(
        steps,
        state,
        metaLabel,
        selectedKey,
        windowState,
    ) {
        var selectedStep = stepByKey(selectedKey) || steps[0]
        var row = document.createElement('div')
        var marker = document.createElement('span')
        var copy = document.createElement('span')
        var meta = document.createElement('span')
        var select = document.createElement('select')
        var duration = document.createElement('span')

        row.className = 'progress-step ' + state
        row.dataset.stepKey = selectedStep.key
        row.dataset.stepWindowState = windowState
        marker.className = 'marker'
        copy.className = 'progress-step-copy selectable'
        meta.className = 'meta'
        meta.textContent = metaLabel
        select.className = 'progress-step-select'
        select.setAttribute('aria-label', metaLabel)
        select.size = selectableStepWindowSize(steps)
        duration.className = 'duration'
        duration.dataset.durationFor = selectedStep.key

        steps.forEach(function (step) {
            select.appendChild(createStepWindowOption(step))
        })

        select.value = selectedStep.key
        select.addEventListener('change', function () {
            updateSelectableStepWindowItem(row, select.value)
            updateStepDurations()
        })

        copy.appendChild(meta)
        copy.appendChild(select)
        copy.appendChild(duration)
        row.appendChild(marker)
        row.appendChild(copy)

        return row
    }

    function renderStepWindow(currentKey) {
        var timeline = progressStepsEl.querySelector(
            '[data-progress-steps-timeline]',
        )
        var selectedWindowState = selectedStepWindowState()

        if (!timeline) {
            return
        }

        timeline.innerHTML = ''

        if (planSteps.length === 0) {
            return
        }

        var currentIndex = planSteps.findIndex(function (step) {
            return step.key === currentKey
        })

        if (currentIndex === -1) {
            currentIndex = Math.min(doneSteps.length, planSteps.length - 1)
        }

        var completedSteps = doneSteps
            .map(function (stepKey) {
                return stepByKey(stepKey)
            })
            .filter(Boolean)
        var nextSteps = planSteps.slice(currentIndex + 1)

        var items = []

        if (completedSteps.length > 0) {
            items.push(
                createSelectableStepWindowItem(
                    completedSteps,
                    'done',
                    progressPreviousStepLabel +
                        ' (' +
                        completedSteps.length +
                        ')',
                    completedSteps[completedSteps.length - 1].key,
                    'previous',
                ),
            )
        }

        items.push(
            createStepWindowItem(
                planSteps[currentIndex],
                currentKey ? 'active' : 'pending',
                progressCurrentStepLabel,
            ),
        )

        if (nextSteps.length > 0) {
            items.push(
                createSelectableStepWindowItem(
                    nextSteps,
                    'pending',
                    progressNextStepLabel + ' (' + nextSteps.length + ')',
                    nextSteps[0].key,
                    'next',
                ),
            )
        }

        items.forEach(function (item) {
            timeline.appendChild(item)
        })

        restoreSelectedStepWindowState(selectedWindowState)
    }

    function updateProgressSummary() {
        var total = planSteps.length
        var completed = doneSteps.length
        var count = progressStepsEl.querySelector('[data-progress-steps-count]')
        var fill = progressStepsEl.querySelector('[data-progress-steps-fill]')
        var percent = total > 0 ? Math.round((completed / total) * 100) : 0

        if (count) {
            count.textContent = completedStepsLabel(completed, total)
        }

        if (fill) {
            fill.style.width = percent + '%'
        }
    }

    function startStepTimer(stepKey) {
        if (activeStepKey === stepKey && activeStepStartedAt) {
            return
        }

        activeStepKey = stepKey
        activeStepStartedAt = Date.now()
        updateStepDurations()

        if (stepTimerInterval) {
            clearInterval(stepTimerInterval)
        }

        stepTimerInterval = setInterval(updateStepDurations, 1000)
    }

    function finishStepTimer(stepKey) {
        if (activeStepKey === stepKey && activeStepStartedAt) {
            stepDurations[stepKey] = Date.now() - activeStepStartedAt
        }

        activeStepKey = null
        activeStepStartedAt = null

        if (stepTimerInterval) {
            clearInterval(stepTimerInterval)
            stepTimerInterval = null
        }

        updateStepDurations()
    }

    function installationProblemForStep(stepLabel) {
        if (!stepLabel) {
            return unknownErrorMessage
        }

        var lowerStepLabel =
            stepLabel.charAt(0).toLowerCase() + stepLabel.slice(1)
        var problemStepLabel = lowerStepLabel.replace(
            /^rebuild\b/,
            'rebuilding',
        )

        var problemMessage =
            installationProblemMessage ||
            'There is a problem with :step. See the log.'

        return problemMessage.replace(':step', problemStepLabel)
    }

    function markStepStatus(currentKey) {
        renderStepWindow(currentKey)
        updateProgressSummary()
        updateStepDurations()
    }

    function showFailurePanel(stepKey) {
        if (!failurePanel) {
            return
        }

        var activeStep = progressStepsEl.querySelector(
            '[data-step-key="' + stepKey + '"]',
        )
        var stepLabel = activeStep
            ? activeStep.querySelector('.label').textContent.trim()
            : ''

        if (activeStep) {
            activeStep.classList.remove('active')
            activeStep.classList.add('failed')
            activeStep.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest',
                inline: 'center',
            })
        }

        failureTitle.textContent = stepLabel
            ? installationProblemForStep(stepLabel)
            : installationFailedHeading
        failureMessage.textContent = stepLabel ? '' : unknownErrorMessage
        failurePanel.hidden = false

        if (currentStepStrip) {
            currentStepStrip.hidden = true
        }

        if (technicalLogPanel) {
            technicalLogPanel.hidden = false
            technicalLogPanel.open = true
        }
    }

    function responseLooksLikeServerTimeout(response, text) {
        return (
            response.status === 502 ||
            response.status === 503 ||
            response.status === 504 ||
            /bad gateway|gateway timeout|service unavailable/i.test(text)
        )
    }

    function renderLines(lines) {
        if (!lines || lines.length === 0) {
            return
        }
        logEl.innerHTML = ''
        lines.forEach(function (entry) {
            var div = document.createElement('div')
            div.className = 'line ' + (entry.type || '')
            div.textContent = entry.line || ''
            logEl.appendChild(div)
        })
        logEl.scrollTop = logEl.scrollHeight
    }

    function appendLogLine(message, type) {
        var div = document.createElement('div')
        div.className = 'line ' + (type || '')
        div.textContent = message
        logEl.appendChild(div)
        logEl.scrollTop = logEl.scrollHeight
    }

    function updateCsrfToken(token) {
        if (!token) {
            return
        }

        csrfToken = token

        var meta = document.querySelector('meta[name="csrf-token"]')
        if (meta) {
            meta.setAttribute('content', token)
        }

        form.querySelectorAll('input[name="_token"]').forEach(function (input) {
            input.value = token
        })
    }

    function refreshCsrfToken() {
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
                var parsed = new DOMParser().parseFromString(html, 'text/html')
                var tokenMeta = parsed.querySelector('meta[name="csrf-token"]')
                var token = tokenMeta ? tokenMeta.getAttribute('content') : ''

                updateCsrfToken(token)

                return token
            })
    }

    function runNextStep(installId, runStepUrl, stepKey) {
        if (aborted || !stepKey) {
            return
        }

        setStatus('running')
        startStepTimer(stepKey)
        markStepStatus(stepKey)
        var currentStep = progressStepsEl.querySelector(
            '[data-step-key="' + stepKey + '"] .label',
        )
        if (currentStepStrip && currentStepName) {
            currentStepName.textContent = currentStep
                ? currentStep.textContent
                : stepKey
            currentStepStrip.hidden = false
        }

        fetch(runStepUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                install_id: installId,
                step: stepKey,
            }),
            credentials: 'same-origin',
        })
            .then(function (response) {
                return response.text().then(function (text) {
                    var payload = {}
                    try {
                        payload = text ? JSON.parse(text) : {}
                    } catch (parseError) {
                        var errorMessage = responseLooksLikeServerTimeout(
                            response,
                            text,
                        )
                            ? serverTimeoutErrorMessage || unknownErrorMessage
                            : 'HTTP ' +
                              response.status +
                              ' ' +
                              (response.statusText || '')

                        payload = {
                            status: 'failed',
                            error: errorMessage,
                        }
                    }
                    return {
                        httpStatus: response.status,
                        payload: payload,
                    }
                })
            })
            .then(function (result) {
                if (result.httpStatus === 419) {
                    console.error(
                        '[Capell Setup] CSRF token mismatch (419) on step "' +
                            stepKey +
                            '" — session expired',
                    )
                    setStatus('failed')
                    finishStepTimer(stepKey)
                    markStepStatus(null)
                    showFailurePanel(stepKey)
                    appendLogLine('✗ ' + sessionExpiredMessage, 'error')
                    backLink.hidden = false
                    return
                }

                if (result.payload.csrfToken) {
                    csrfToken = result.payload.csrfToken
                }

                if (result.payload.redirectUrl) {
                    window.location.href = result.payload.redirectUrl
                    return
                }

                renderLines(result.payload.lines || [])

                if (
                    result.payload.status === 'failed' ||
                    result.httpStatus >= 400
                ) {
                    console.error(
                        '[Capell Setup] Step "' + stepKey + '" failed:',
                        result.payload.error || result.payload,
                        'HTTP ' + result.httpStatus,
                    )
                    if (result.payload.error) {
                        appendLogLine(
                            '✗ ' +
                                (result.payload.errorClass
                                    ? result.payload.errorClass + ': '
                                    : '') +
                                result.payload.error,
                            'error',
                        )
                    }
                    if (result.payload.remediation) {
                        appendLogLine(
                            'Fix: ' + result.payload.remediation,
                            'error',
                        )
                    }
                    setStatus('failed')
                    finishStepTimer(stepKey)
                    markStepStatus(null)
                    showFailurePanel(stepKey)
                    backLink.hidden = false
                    reportLink.hidden = false
                    if (reportDownloadButton) {
                        reportDownloadButton.hidden = false
                    }
                    return
                }

                if (doneSteps.indexOf(stepKey) === -1) {
                    doneSteps.push(stepKey)
                }
                finishStepTimer(stepKey)

                if (
                    result.payload.status === 'complete' ||
                    !result.payload.nextStep
                ) {
                    if (activeSuccessUrl) {
                        window.location.href = activeSuccessUrl
                        return
                    }

                    setStatus('complete')
                    markStepStatus(null)
                    showInstalledPanel()
                    return
                }

                runNextStep(installId, runStepUrl, result.payload.nextStep)
            })
            .catch(function (err) {
                // Transient network error — retry the same step after a short delay
                console.error(
                    '[Capell Setup] Network error on step "' + stepKey + '":',
                    err,
                )
                appendLogLine(
                    'Network error while running "' + stepKey + '". Retrying…',
                    'error',
                )
                setTimeout(function () {
                    runNextStep(installId, runStepUrl, stepKey)
                }, 2000)
            })
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault()
        clearFieldErrors()

        if (currentInstallerStep !== lastInstallerStep()) {
            continueToNextInstallerStep()

            return
        }

        if (!validateAllInstallerSteps()) {
            return
        }

        setSubmitting(true)

        aborted = true
        completedInstallFlow = false
        setFlowStep('installing')
        doneSteps = []
        planSteps = []
        activeStepKey = null
        activeStepStartedAt = null
        stepDurations = {}
        if (stepTimerInterval) {
            clearInterval(stepTimerInterval)
            stepTimerInterval = null
        }
        progressStepsEl.innerHTML = ''
        logEl.innerHTML =
            '<span class="line empty">' + waitingForOutputMessage + '</span>'
        logEl.hidden = false
        progressStepsEl.hidden = false
        if (failurePanel) {
            failurePanel.hidden = true
        }
        if (technicalLogPanel) {
            technicalLogPanel.hidden = false
            technicalLogPanel.open = false
        }
        adminLink.hidden = true
        backLink.hidden = true
        reportLink.hidden = true
        if (reportDownloadButton) {
            reportDownloadButton.hidden = true
        }

        showProgressView()
        setStatus('queued')

        function submitInstallForm(hasRetried) {
            var formData = new FormData(form)
            formData.set('_token', csrfToken)

            return fetch(form.action, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: formData,
                credentials: 'same-origin',
            })
                .then(function (response) {
                    return response.text().then(function (text) {
                        if (response.status === 419) {
                            return {
                                status: 419,
                                payload: null,
                                csrf: true,
                            }
                        }
                        var payload = {}
                        try {
                            payload = text ? JSON.parse(text) : {}
                        } catch (parseError) {
                            var errorMessage = responseLooksLikeServerTimeout(
                                response,
                                text,
                            )
                                ? serverTimeoutErrorMessage ||
                                  unknownErrorMessage
                                : 'HTTP ' +
                                  response.status +
                                  ' ' +
                                  (response.statusText || '')

                            payload = text
                                ? {
                                      error: errorMessage,
                                  }
                                : {}
                        }
                        return {
                            status: response.status,
                            payload: payload,
                        }
                    })
                })
                .then(function (result) {
                    if (result.csrf) {
                        if (!hasRetried) {
                            return refreshCsrfToken().then(function (token) {
                                if (token) {
                                    return submitInstallForm(true)
                                }

                                return result
                            })
                        }

                        console.error(
                            '[Capell Setup] CSRF token mismatch (419) on form submit — session expired',
                        )
                        progressView.classList.remove('active')
                        formView.classList.remove('hidden')
                        setFlowStep(currentInstallerStep)
                        setSubmitting(false)
                        errorsList.innerHTML =
                            '<li>' + sessionExpiredMessage + '</li>'
                        errorsBox.hidden = false
                        return
                    }

                    if (result.status === 422) {
                        progressView.classList.remove('active')
                        formView.classList.remove('hidden')
                        setFlowStep(currentInstallerStep)
                        showFieldErrors(result.payload.errors || {})
                        setSubmitting(false)
                        return
                    }

                    // Queue-mode opt-in: server tells us where to follow the queued install
                    if (
                        result.status >= 200 &&
                        result.status < 300 &&
                        result.payload.redirectUrl
                    ) {
                        window.location.href = result.payload.redirectUrl
                        return
                    }

                    if (
                        result.status >= 200 &&
                        result.status < 300 &&
                        result.payload.installId &&
                        result.payload.runStepUrl
                    ) {
                        if (result.payload.csrfToken) {
                            csrfToken = result.payload.csrfToken
                        }
                        activeSuccessUrl = result.payload.successUrl || ''
                        renderPlanSteps(result.payload.plan || [])
                        aborted = false
                        if (result.payload.reportUrl) {
                            reportLink.action = result.payload.reportUrl
                            if (reportDownloadButton) {
                                reportDownloadButton.setAttribute(
                                    'data-download-filename',
                                    'capell-install-' +
                                        result.payload.installId +
                                        '.json',
                                )
                            }
                            reportLink.hidden = false
                            if (reportDownloadButton) {
                                reportDownloadButton.hidden = false
                            }
                        }

                        if (result.payload.logPath) {
                            var hint = document.createElement('div')
                            hint.className = 'line empty'
                            hint.textContent =
                                'Log file: ' + result.payload.logPath
                            logEl.appendChild(hint)
                        }

                        runNextStep(
                            result.payload.installId,
                            result.payload.runStepUrl,
                            result.payload.nextStep,
                        )
                        return
                    }

                    // Unexpected success response — fall back to error
                    console.error(
                        '[Capell Setup] Unexpected response from install endpoint:',
                        result,
                    )
                    progressView.classList.remove('active')
                    formView.classList.remove('hidden')
                    setFlowStep(currentInstallerStep)
                    setSubmitting(false)
                    var startError =
                        result.payload.error || result.payload.message
                    errorsList.innerHTML =
                        '<li>' + (startError || unknownErrorMessage) + '</li>'
                    errorsBox.hidden = false
                })
                .catch(function (err) {
                    console.error(
                        '[Capell Setup] Network error on form submit:',
                        err,
                    )
                    progressView.classList.remove('active')
                    formView.classList.remove('hidden')
                    setFlowStep(currentInstallerStep)
                    setSubmitting(false)
                    errorsList.innerHTML =
                        '<li>' + networkErrorMessage + '</li>'
                    errorsBox.hidden = false
                })
        }

        refreshCsrfToken()
            .catch(function () {
                return null
            })
            .then(function () {
                submitInstallForm(false)
            })
    })

    form.addEventListener('keydown', function (event) {
        if (
            event.key !== 'Enter' ||
            currentInstallerStep === lastInstallerStep() ||
            event.target.tagName === 'TEXTAREA'
        ) {
            return
        }

        event.preventDefault()
        clearFieldErrors()
        continueToNextInstallerStep()
    })
})()
