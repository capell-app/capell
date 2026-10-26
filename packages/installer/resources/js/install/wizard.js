;(function (global) {
    'use strict'

    // Wizard navigation: multi-step form sections, the left-rail step
    // triggers, the flow rail, per-step HTML validation, and field/global
    // error display on the form view.
    function createWizard(options) {
        var form = options.form

        var stepTriggers = document.querySelectorAll('[data-step-trigger]')
        var stepSections = document.querySelectorAll('[data-installer-step]')
        var flowItems = document.querySelectorAll('[data-flow-step]')
        var backStepButton = document.querySelector('[data-step-back]')
        var continueStepButton = document.querySelector('[data-step-continue]')
        var submitButton = document.getElementById('submit-button')
        var errorsBox = document.getElementById('errors')
        var errorsList = document.getElementById('errors-list')

        var installerStepOrder = ['readiness', 'site', 'packages', 'options']
        var flowStepOrder = installerStepOrder.concat(['installing'])
        var currentInstallerStep = 'readiness'
        var completedInstallFlow = false

        function stepIndex(stepName) {
            return installerStepOrder.indexOf(stepName)
        }

        function lastInstallerStep() {
            return installerStepOrder[installerStepOrder.length - 1]
        }

        function installerStepTransitionDirection(stepName) {
            var currentStepIndex = stepIndex(currentInstallerStep)
            var nextStepIndex = stepIndex(stepName)

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
            var activeStepIndex = stepIndex(stepName)
            var isIntentionalTransition =
                stepName !== currentInstallerStep &&
                (transitionDirection === 'forward' ||
                    transitionDirection === 'back')
            var shouldAnimateStep =
                isIntentionalTransition && !prefersReducedMotion()

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
                var triggerStepIndex = stepIndex(triggerStep)

                trigger.classList.toggle('active', isActive)
                trigger.classList.toggle(
                    'done',
                    triggerStepIndex >= 0 && triggerStepIndex < activeStepIndex,
                )
                trigger.classList.toggle(
                    'pending',
                    triggerStepIndex > activeStepIndex,
                )

                if (isActive) {
                    trigger.setAttribute('aria-current', 'step')
                } else {
                    trigger.removeAttribute('aria-current')
                }
            })

            setFlowStep(stepName)

            if (backStepButton) {
                backStepButton.disabled = activeStepIndex === 0
            }

            if (continueStepButton && submitButton) {
                var isSubmitStep =
                    activeStepIndex === installerStepOrder.length - 1
                continueStepButton.hidden = isSubmitStep
                submitButton.hidden = !isSubmitStep
            }

            var activeSection = form.querySelector(
                '[data-installer-step="' + stepName + '"]',
            )
            var heading = null
            if (activeSection) {
                heading = activeSection.matches('[data-step-heading]')
                    ? activeSection
                    : activeSection.querySelector('[data-step-heading]')
            }
            var workspace = document.getElementById('installer-workspace')
            if (workspace && heading) {
                workspace.setAttribute('aria-labelledby', heading.id)
            }

            if (isIntentionalTransition && heading) {
                heading.focus({ preventScroll: true })
                heading.scrollIntoView({
                    block: 'start',
                    behavior: prefersReducedMotion() ? 'instant' : 'smooth',
                })
            }
        }

        function prefersReducedMotion() {
            return window.matchMedia('(prefers-reduced-motion: reduce)').matches
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
                behavior: prefersReducedMotion() ? 'instant' : 'smooth',
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
            var nextStepIndex = stepIndex(currentInstallerStep) + 1

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

        function clearFieldErrors() {
            form.querySelectorAll('.field.has-error').forEach(function (node) {
                node.classList.remove('has-error')
                var msg = node.querySelector('.field-error')
                if (msg) {
                    msg.textContent = ''
                    node.querySelectorAll('[aria-describedby]').forEach(
                        function (input) {
                            var descriptions = input
                                .getAttribute('aria-describedby')
                                .split(/\s+/)
                                .filter(function (id) {
                                    return id !== msg.id
                                })
                            if (descriptions.length) {
                                input.setAttribute(
                                    'aria-describedby',
                                    descriptions.join(' '),
                                )
                            } else {
                                input.removeAttribute('aria-describedby')
                            }
                        },
                    )
                }
            })
            form.querySelectorAll('[aria-invalid]').forEach(function (input) {
                input.removeAttribute('aria-invalid')
            })
            errorsBox.hidden = true
            errorsList.innerHTML = ''
        }

        function errorMessage(error, useSummary) {
            if (typeof error !== 'object' || error === null) {
                return error || ''
            }

            return (useSummary && error.summary) || error.field || ''
        }

        function showGlobalErrors(messages) {
            errorsList.innerHTML = ''
            messages.forEach(function (message) {
                var item = document.createElement('li')
                item.textContent = message
                errorsList.appendChild(item)
            })
            errorsBox.hidden = messages.length === 0
        }

        function showFieldErrors(errors) {
            clearFieldErrors()
            var firstInvalidStep = null
            var firstInvalidInput = null
            var unmatchedMessages = []

            Object.keys(errors).forEach(function (name) {
                var fieldNode = findFieldNode(name)
                var error = errors[name][0] || ''
                var message = errorMessage(error, false)
                if (fieldNode) {
                    fieldNode.classList.add('has-error')
                    var fieldStep = fieldNode.closest('[data-installer-step]')
                    if (!firstInvalidStep && fieldStep) {
                        firstInvalidStep = fieldStep.dataset.installerStep
                    }
                    var inputs = fieldNode.querySelectorAll(
                        'input:not([type=hidden]):not(:disabled), ' +
                            'select:not(:disabled), textarea:not(:disabled)',
                    )
                    var input =
                        Array.prototype.find.call(inputs, function (candidate) {
                            return candidate.name === name
                        }) || inputs[0]
                    if (input) {
                        input.setAttribute('aria-invalid', 'true')
                    }
                    var msgEl = fieldNode.querySelector('.field-error')
                    if (msgEl) {
                        msgEl.textContent = message
                        if (input) {
                            if (!msgEl.id) {
                                msgEl.id =
                                    'installer-error-' +
                                    name.replace(/[^a-z0-9_-]/gi, '-')
                            }
                            var descriptions = (
                                input.getAttribute('aria-describedby') || ''
                            )
                                .split(/\s+/)
                                .filter(Boolean)
                            if (descriptions.indexOf(msgEl.id) === -1) {
                                descriptions.push(msgEl.id)
                            }
                            input.setAttribute(
                                'aria-describedby',
                                descriptions.join(' '),
                            )
                        }
                    }
                    if (!firstInvalidInput && input) {
                        firstInvalidInput = input
                    }
                    return
                }

                unmatchedMessages.push(errorMessage(error, true))
            })

            if (firstInvalidStep) {
                setInstallerStep(firstInvalidStep, 'none')
            }

            showGlobalErrors(unmatchedMessages)
            if (firstInvalidInput) focusFirstInvalidInput([firstInvalidInput])
        }

        function showGlobalError(message) {
            showGlobalErrors([message])
        }

        stepTriggers.forEach(function (trigger) {
            trigger.addEventListener('click', function () {
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
                var previousStepIndex = stepIndex(currentInstallerStep) - 1

                if (installerStepOrder[previousStepIndex]) {
                    setInstallerStep(
                        installerStepOrder[previousStepIndex],
                        'back',
                    )
                }
            })
        }

        setInstallerStep('readiness', 'none')

        return {
            currentStep: function () {
                return currentInstallerStep
            },
            isOnLastStep: function () {
                return currentInstallerStep === lastInstallerStep()
            },
            continueToNext: continueToNextInstallerStep,
            validateAll: validateAllInstallerSteps,
            setFlowStep: setFlowStep,
            beginInstallingFlow: function () {
                completedInstallFlow = false
                setFlowStep('installing')
            },
            completeInstallingFlow: function () {
                completedInstallFlow = true
                setFlowStep('installing')
            },
            clearFieldErrors: clearFieldErrors,
            showFieldErrors: showFieldErrors,
            showGlobalError: showGlobalError,
        }
    }

    global.CapellInstaller = global.CapellInstaller || {}
    global.CapellInstaller.createWizard = createWizard
})(typeof globalThis !== 'undefined' ? globalThis : window)
