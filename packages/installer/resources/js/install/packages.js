;(function (global) {
    'use strict'

    var support = global.CapellInstaller.support

    // Package dependency selection: keeps required dependencies checked and
    // disabled (with a hidden input so they still submit), explains which
    // selection requires them, and drives the selection-driven summaries
    // (package count, submit label, theme selector, marketplace note).
    function createPackageSelection(options) {
        var config = options.config
        var messages = options.messages
        var submitButton = options.submitButton

        var requirementsMap = config.requirementsMap || {}
        var themePackageNames = config.themePackageNames || {}
        var installedThemeKeys = config.installedThemeKeys || []
        var requiredByTemplate = messages.requiredByPackages || ''
        var packageSelectAllLabel = messages.packageSelectAll || 'Select all'
        var packageUnselectAllLabel =
            messages.packageUnselectAll || 'Unselect all'
        var submitLabel = messages.submitLabel || 'Install Capell'
        var installPackageLabel =
            messages.installPackageLabel || 'Install __count__ package'
        var installPackagesLabel =
            messages.installPackagesLabel || 'Install __count__ packages'
        var installingPackageLabel =
            messages.installingPackageLabel || 'Installing __count__ package'
        var installingPackagesLabel =
            messages.installingPackagesLabel || 'Installing __count__ packages'

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
        var submitButtonLabel = submitButton
            ? submitButton.querySelector('[data-submit-label]')
            : null

        var selectionListeners = []

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

        function getDirectlyCheckedPackages() {
            var checked = []

            document
                .querySelectorAll('[data-package-checkbox]:checked')
                .forEach(function (input) {
                    if (input.dataset.autoRequired !== 'true') {
                        checked.push(input.value)
                    }
                })

            return checked
        }

        function selectedPackageCount() {
            return getAllCheckedPackages().length
        }

        function isChecked(packageName) {
            var checkbox = getCheckbox(packageName)

            return Boolean(checkbox && checkbox.checked)
        }

        function submitButtonText(isSubmitting) {
            var packageCount = selectedPackageCount()

            if (packageCount < 1) {
                return submitLabel
            }

            return isSubmitting
                ? support.packageCountLabel(
                      installingPackageLabel,
                      installingPackagesLabel,
                      packageCount,
                  )
                : support.packageCountLabel(
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

        function update() {
            showPackageSelectionLists()

            var directlyChecked = getDirectlyCheckedPackages()
            var required = support.resolveRequiredPackages(
                directlyChecked,
                requirementsMap,
            )
            var requiredBy = support.resolveRequiredByPackages(
                directlyChecked,
                requirementsMap,
            )

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
                    if (!checkbox.checked) {
                        checkbox.dataset.autoRequired = 'true'
                    }
                    checkbox.checked = true
                    checkbox.disabled = true
                    ensureRequiredHiddenInput(checkbox, packageName)
                    if (badge) {
                        badge.textContent = requiredByText(
                            packageName,
                            requiredBy,
                        )
                        badge.style.display = 'block'
                    }
                    if (row) {
                        row.style.opacity = '0.75'
                        row.style.cursor = 'not-allowed'
                    }
                } else {
                    checkbox.disabled = false
                    removeRequiredHiddenInput(packageName)
                    if (checkbox.dataset.autoRequired === 'true') {
                        checkbox.checked = false
                        delete checkbox.dataset.autoRequired
                    }
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
            updateMarketplaceLaterNote()
            updatePackageSelectAllStates()
            selectionListeners.forEach(function (listener) {
                listener()
            })
        }

        function packageSelectAllCheckboxes(scope) {
            var selector =
                scope === 'core'
                    ? '[data-package-checkbox][data-package-core="true"]'
                    : '[data-package-checkbox][data-package-extension="true"]'

            return Array.prototype.slice.call(
                document.querySelectorAll(selector),
            )
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

            marketplaceLaterNote.hidden = !isChecked('capell-app/marketplace')
        }

        function selectedThemeKeys() {
            var checkedPackages = getAllCheckedPackages()
            var selectedKeys = installedThemeKeys.slice()

            Object.keys(themePackageNames).forEach(function (themeKey) {
                if (
                    checkedPackages.indexOf(themePackageNames[themeKey]) !== -1
                ) {
                    selectedKeys.push(themeKey)
                }
            })

            return selectedKeys.filter(function (themeKey, index, keys) {
                return keys.indexOf(themeKey) === index
            })
        }

        function updateThemeSelector() {
            var section = document.querySelector('[data-theme-selector]')
            var themeOptions = document.querySelectorAll('[data-theme-option]')

            if (!section || themeOptions.length === 0) {
                return
            }

            var keys = selectedThemeKeys()
            var shouldShow = keys.length > 0

            section.classList.toggle('hidden', !shouldShow)
            var checkedAvailable = false

            themeOptions.forEach(function (option) {
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

        document
            .querySelectorAll('[data-package-checkbox]')
            .forEach(function (input) {
                input.addEventListener('change', update)
            })

        packageSelectAllInputs.forEach(function (input) {
            input.addEventListener('change', function () {
                var checkboxes = packageSelectAllCheckboxes(
                    input.dataset.packageSelectAll,
                )

                checkboxes.forEach(function (checkbox) {
                    checkbox.checked = input.checked
                    delete checkbox.dataset.autoRequired
                })

                update()
            })
        })

        return {
            update: update,
            isChecked: isChecked,
            selectedPackageCount: selectedPackageCount,
            updateSubmitButtonLabel: updateSubmitButtonLabel,
            onSelectionChanged: function (listener) {
                selectionListeners.push(listener)
            },
        }
    }

    global.CapellInstaller = global.CapellInstaller || {}
    global.CapellInstaller.createPackageSelection = createPackageSelection
})(typeof globalThis !== 'undefined' ? globalThis : window)
