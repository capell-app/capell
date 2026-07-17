;(function (global) {
    'use strict'

    function createProgress(options) {
        var messages = options.messages || {}
        var support = global.CapellInstaller.support
        var formView = document.getElementById('form-view')
        var progressView = document.getElementById('progress-view')
        var status = document.getElementById('progress-status')
        var statusLabel = document.getElementById('progress-status-label')
        var loader = document.getElementById('progress-loader')
        var currentStrip = document.getElementById('current-step-strip')
        var currentName = document.getElementById('current-step-name')
        var failurePanel = document.getElementById('failure-panel')
        var failureTitle = document.getElementById('failure-title')
        var failureMessage = document.getElementById('failure-message')
        var technicalLog = document.getElementById('technical-log-panel')
        var stepsElement = document.getElementById('progress-steps')
        var log = document.getElementById('log')
        var adminLink = document.getElementById('admin-link')
        var backLink = document.getElementById('back-link')
        var reportLink = document.getElementById('report-link')
        var reportDownload = document.querySelector(
            '[data-report-download-button]',
        )
        var planSteps = []
        var doneSteps = []
        var activeStepKey = null
        var activeStepStartedAt = null
        var durations = {}
        var timer = null

        function showFormView() {
            progressView.classList.remove('active')
            formView.classList.remove('hidden')
        }

        function showProgressView() {
            formView.classList.add('hidden')
            progressView.classList.add('active')
        }

        function setStatus(value) {
            status.classList.remove('queued', 'running', 'complete', 'failed')
            status.classList.add(value)
            statusLabel.textContent =
                (messages.statuses || {})[value] ||
                value.charAt(0).toUpperCase() + value.slice(1)
            if (loader)
                loader.hidden = value === 'complete' || value === 'failed'
            if (currentStrip && (value === 'complete' || value === 'failed')) {
                currentStrip.hidden = true
            }
        }

        function stepByKey(key) {
            return planSteps.find(function (step) {
                return step.key === key
            })
        }

        function durationText(key) {
            if (durations[key] !== undefined)
                return support.formatDuration(durations[key])
            if (key === activeStepKey && activeStepStartedAt) {
                return support.formatDuration(Date.now() - activeStepStartedAt)
            }
            return ''
        }

        function updateDurations() {
            stepsElement
                .querySelectorAll('[data-duration-for]')
                .forEach(function (node) {
                    node.textContent = durationText(node.dataset.durationFor)
                })
        }

        function createItem(step, state, metaLabel) {
            var row = document.createElement('div')
            row.className = 'progress-step ' + state
            row.dataset.stepKey = step.key
            row.innerHTML =
                '<span class="marker"></span><span class="progress-step-copy"></span>'
            var copy = row.querySelector('.progress-step-copy')
            var meta = document.createElement('span')
            var label = document.createElement('span')
            var duration = document.createElement('span')
            meta.className = 'meta'
            meta.textContent = metaLabel
            label.className = 'label'
            label.textContent = step.label
            duration.className = 'duration'
            duration.dataset.durationFor = step.key
            copy.appendChild(meta)
            copy.appendChild(label)
            copy.appendChild(duration)
            return row
        }

        function createSelectItem(
            steps,
            state,
            metaLabel,
            selectedKey,
            windowState,
        ) {
            var selected = stepByKey(selectedKey) || steps[0]
            var row = createItem(selected, state, metaLabel)
            var copy = row.querySelector('.progress-step-copy')
            var oldLabel = copy.querySelector('.label')
            var select = document.createElement('select')
            select.className = 'progress-step-select'
            select.setAttribute('aria-label', metaLabel)
            select.size = support.selectableStepWindowSize(steps)
            steps.forEach(function (step) {
                var option = document.createElement('option')
                option.value = step.key
                option.textContent = step.label
                select.appendChild(option)
            })
            select.value = selected.key
            copy.classList.add('selectable')
            copy.replaceChild(select, oldLabel)
            row.dataset.stepWindowState = windowState
            select.addEventListener('change', function () {
                row.dataset.stepKey = select.value
                row.querySelector('.duration').dataset.durationFor =
                    select.value
                updateDurations()
            })
            return row
        }

        function renderStepWindow(currentKey) {
            var timeline = stepsElement.querySelector(
                '[data-progress-steps-timeline]',
            )
            if (!timeline) return
            timeline.innerHTML = ''
            if (planSteps.length === 0) return
            var index = planSteps.findIndex(function (step) {
                return step.key === currentKey
            })
            if (index === -1)
                index = Math.min(doneSteps.length, planSteps.length - 1)
            var completed = doneSteps.map(stepByKey).filter(Boolean)
            var next = planSteps.slice(index + 1)
            if (completed.length) {
                timeline.appendChild(
                    createSelectItem(
                        completed,
                        'done',
                        (messages.progressPreviousStep || 'Completed') +
                            ' (' +
                            completed.length +
                            ')',
                        completed[completed.length - 1].key,
                        'previous',
                    ),
                )
            }
            timeline.appendChild(
                createItem(
                    planSteps[index],
                    currentKey ? 'active' : 'pending',
                    messages.progressCurrentStep || 'Current step',
                ),
            )
            if (next.length) {
                timeline.appendChild(
                    createSelectItem(
                        next,
                        'pending',
                        (messages.progressNextStep || 'Next') +
                            ' (' +
                            next.length +
                            ')',
                        next[0].key,
                        'next',
                    ),
                )
            }
        }

        function updateSummary() {
            var count = stepsElement.querySelector(
                '[data-progress-steps-count]',
            )
            var fill = stepsElement.querySelector('[data-progress-steps-fill]')
            if (count)
                count.textContent = support.completedStepsLabel(
                    messages.progressCompletedSteps ||
                        '__completed__ of __total__ complete',
                    doneSteps.length,
                    planSteps.length,
                )
            if (fill)
                fill.style.width =
                    (planSteps.length
                        ? Math.round(
                              (doneSteps.length / planSteps.length) * 100,
                          )
                        : 0) + '%'
        }

        function renderPlanSteps(plan) {
            planSteps = plan || []
            stepsElement.innerHTML =
                '<div class="progress-steps-summary"><span class="progress-steps-count" data-progress-steps-count></span><span class="progress-steps-track" aria-hidden="true"><span class="progress-steps-fill" data-progress-steps-fill></span></span></div><div class="progress-steps-timeline" data-progress-steps-timeline></div>'
            renderStepWindow(null)
            updateSummary()
        }

        function markStepStatus(key) {
            renderStepWindow(key)
            updateSummary()
            updateDurations()
            var current = stepsElement.querySelector(
                '[data-step-key="' + key + '"] .label',
            )
            if (currentStrip && currentName && key) {
                currentName.textContent = current ? current.textContent : key
                currentStrip.hidden = false
            }
        }

        function startStepTimer(key) {
            if (activeStepKey === key && activeStepStartedAt) return
            activeStepKey = key
            activeStepStartedAt = Date.now()
            updateDurations()
            if (timer) clearInterval(timer)
            timer = setInterval(updateDurations, 1000)
        }

        function finishStepTimer(key) {
            if (activeStepKey === key && activeStepStartedAt)
                durations[key] = Date.now() - activeStepStartedAt
            activeStepKey = null
            activeStepStartedAt = null
            if (timer) {
                clearInterval(timer)
                timer = null
            }
            updateDurations()
        }

        function completeStep(key) {
            if (doneSteps.indexOf(key) === -1) doneSteps.push(key)
            finishStepTimer(key)
        }

        function showFailurePanel(key) {
            if (!failurePanel) return
            var active = stepsElement.querySelector(
                '[data-step-key="' + key + '"]',
            )
            var label =
                active && active.querySelector('.label')
                    ? active.querySelector('.label').textContent.trim()
                    : ''
            if (active) {
                active.classList.remove('active')
                active.classList.add('failed')
                active.scrollIntoView({
                    behavior: 'smooth',
                    block: 'nearest',
                    inline: 'center',
                })
            }
            failureTitle.textContent = label
                ? support.installationProblemForStep(
                      label,
                      messages.installationProblemMessage,
                      messages.unknownError,
                  )
                : messages.installationFailedHeading
            failureMessage.textContent = label ? '' : messages.unknownError
            failurePanel.hidden = false
            if (currentStrip) currentStrip.hidden = true
            if (technicalLog) {
                technicalLog.hidden = false
                technicalLog.open = true
            }
        }

        function renderLines(lines) {
            if (!lines || !lines.length) return
            log.innerHTML = ''
            lines.forEach(function (entry) {
                appendLogLine(entry.line || '', entry.type || '')
            })
        }

        function appendLogLine(message, type) {
            var line = document.createElement('div')
            line.className = 'line ' + (type || '')
            line.textContent = message
            log.appendChild(line)
            log.scrollTop = log.scrollHeight
        }

        function reset() {
            doneSteps = []
            planSteps = []
            activeStepKey = null
            activeStepStartedAt = null
            durations = {}
            if (timer) {
                clearInterval(timer)
                timer = null
            }
            stepsElement.innerHTML = ''
            log.innerHTML =
                '<span class="line empty">' +
                (messages.waitingForOutput || '') +
                '</span>'
            log.hidden = false
            stepsElement.hidden = false
            if (failurePanel) failurePanel.hidden = true
            if (technicalLog) {
                technicalLog.hidden = false
                technicalLog.open = false
            }
            adminLink.hidden = true
            backLink.hidden = true
            reportLink.hidden = true
            if (reportDownload) reportDownload.hidden = true
        }

        function configureReport(url, installId) {
            if (!url) return
            reportLink.action = url
            reportLink.hidden = false
            if (reportDownload && installId) {
                reportDownload.dataset.downloadFilename =
                    'capell-install-' + installId + '.json'
            }
            if (reportDownload) {
                reportDownload.hidden = false
            }
        }

        function showInstalledPanel() {
            if (failurePanel) failurePanel.hidden = true
            stepsElement.hidden = true
            if (technicalLog) technicalLog.hidden = true
            reportLink.hidden = true
            if (reportDownload) reportDownload.hidden = true
            adminLink.hidden = true
            backLink.hidden = true
        }

        return {
            showFormView: showFormView,
            showProgressView: showProgressView,
            setStatus: setStatus,
            renderPlanSteps: renderPlanSteps,
            markStepStatus: markStepStatus,
            startStepTimer: startStepTimer,
            finishStepTimer: finishStepTimer,
            completeStep: completeStep,
            showFailurePanel: showFailurePanel,
            renderLines: renderLines,
            appendLogLine: appendLogLine,
            reset: reset,
            configureReport: configureReport,
            showInstalledPanel: showInstalledPanel,
            backLink: backLink,
        }
    }

    global.CapellInstaller = global.CapellInstaller || {}
    global.CapellInstaller.createProgress = createProgress
})(typeof globalThis !== 'undefined' ? globalThis : window)
