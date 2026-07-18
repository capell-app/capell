;(function (global) {
    'use strict'

    var support = {
        resolveRequiredPackages: function (checkedNames, requirementsMap) {
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
        },

        resolveRequiredByPackages: function (checkedNames, requirementsMap) {
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
        },

        packageCountLabel: function (singularTemplate, pluralTemplate, count) {
            return (count === 1 ? singularTemplate : pluralTemplate).replace(
                '__count__',
                count,
            )
        },

        completedStepsLabel: function (template, completed, total) {
            return template
                .replace('__completed__', String(completed))
                .replace('__total__', String(total))
        },

        formatDuration: function (milliseconds) {
            var totalSeconds = Math.max(0, Math.round(milliseconds / 1000))
            var minutes = Math.floor(totalSeconds / 60)
            var seconds = totalSeconds % 60

            if (minutes > 0) {
                return minutes + 'm ' + String(seconds).padStart(2, '0') + 's'
            }

            return totalSeconds + 's'
        },

        responseLooksLikeServerTimeout: function (httpStatus, text) {
            return (
                httpStatus === 502 ||
                httpStatus === 503 ||
                httpStatus === 504 ||
                /bad gateway|gateway timeout|service unavailable/i.test(text)
            )
        },

        installationProblemForStep: function (
            stepLabel,
            problemMessageTemplate,
            unknownErrorMessage,
        ) {
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
                problemMessageTemplate ||
                'There is a problem with :step. See the log.'

            return problemMessage.replace(':step', problemStepLabel)
        },

        selectableStepWindowSize: function (steps) {
            return Math.min(Math.max(steps.length, 2), 6)
        },
    }

    global.CapellInstaller = global.CapellInstaller || {}
    global.CapellInstaller.support = support
})(typeof globalThis !== 'undefined' ? globalThis : window)
