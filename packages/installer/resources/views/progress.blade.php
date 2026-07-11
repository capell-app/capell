@extends('capell-installer::layouts.installer')

@section('title', __('capell-installer::installer.progress_title'))
@section('bodyClass', 'installer-screen-progress')

@section('content')
    <main
        class="panel"
        role="main"
    >
        <header class="panel-header">
            <div class="brand-block">
                <div
                    class="brand-logo"
                    aria-label="Capell"
                    role="img"
                >
                    @if (view()->exists('capell-admin::img.logo'))
                        @include('capell-admin::img.logo')
                    @else
                        <span>Capell</span>
                    @endif
                </div>
                <h1>
                    {{ __('capell-installer::installer.progress_heading') }}
                </h1>
                <p>
                    {{ __('capell-installer::installer.progress_title') }}
                </p>
            </div>
            <span
                aria-live="polite"
                class="status {{ $installStatus }}"
                id="status-indicator"
                role="status"
            >
                <span class="dot"></span>
                <span class="label">{{ ucfirst($installStatus) }}</span>
            </span>
        </header>

        <div class="panel-body">
            <section class="installer-workspace">
                <div class="progress-report-bar">
                    <form
                        action="{{ $reportUrl }}"
                        class="progress-report-link"
                        id="report-link"
                        method="GET"
                        target="_blank"
                    >
                        <button
                            data-download-filename="{{ $reportDownloadFilename }}"
                            type="submit"
                        >
                            {{ __('capell-installer::installer.download_report') }}
                        </button>
                    </form>
                    <a
                        download="{{ $reportDownloadFilename }}"
                        hidden
                        href="{{ $reportUrl }}"
                    >
                        {{ __('capell-installer::installer.download_report') }}
                    </a>
                </div>
                <pre
                    aria-atomic="false"
                    aria-live="polite"
                    aria-relevant="additions text"
                    class="log"
                    id="log"
                    role="log"
                ><span class="line empty">{{ __('capell-installer::installer.waiting_for_output') }}</span></pre>
            </section>

            <aside class="installer-summary">
                <div class="installer-summary-panel">
                    <p class="summary-title">
                        {{ __('capell-installer::installer.workspace_install_review') }}
                    </p>
                    <ul class="summary-list">
                        <li>
                            <strong>
                                {{ __('capell-installer::installer.workspace_execution') }}
                            </strong>
                            <span id="summary-status">
                                {{ ucfirst($installStatus) }}
                            </span>
                        </li>
                    </ul>
                </div>
            </aside>
        </div>

        <footer
            class="panel-footer"
            id="actions-footer"
            hidden
        >
            <a
                class="button secondary"
                href="{{ route('capell-installer.show') }}"
                id="back-link"
                hidden
            >
                {{ __('capell-installer::installer.back_to_installer') }}
            </a>
            <a
                class="button primary"
                href="{{ url('/admin') }}"
                id="admin-link"
                hidden
            >
                {{ __('capell-installer::installer.go_to_admin') }}
            </a>
        </footer>
    </main>

    <script>
        ;(function () {
            ;{{-- format-ignore-start --}}
            var endpoint = @json(route('capell-installer.progress.data', ['installId' => $installId]));
            var statusEl = document.getElementById('status-indicator')
            var labelEl = statusEl.querySelector('.label')
            var logEl = document.getElementById('log')
            var summaryStatus = document.getElementById('summary-status')
            var actionsFooter = document.getElementById('actions-footer')
            var adminLink = document.getElementById('admin-link')
            var backLink = document.getElementById('back-link')
            var restartInstallLabel = @json(__('capell-installer::installer.restart_install'));
            {{-- format-ignore-end --}}
            var stopped = false
            var renderedLineCount = 0
            var currentStatus = statusEl.classList.contains(
                '{{ $installStatus }}',
            )
                ? '{{ $installStatus }}'
                : null

            function applyStatus(status) {
                var statusChanged = status !== currentStatus

                statusEl.classList.remove(
                    'idle',
                    'queued',
                    'running',
                    'complete',
                    'cancelled',
                    'failed',
                )
                statusEl.classList.add(status)

                if (statusChanged) {
                    currentStatus = status
                    labelEl.textContent =
                        status.charAt(0).toUpperCase() + status.slice(1)
                }

                if (summaryStatus && statusChanged) {
                    summaryStatus.textContent = labelEl.textContent
                }
                if (status === 'complete') {
                    actionsFooter.hidden = false
                    adminLink.hidden = false
                    backLink.hidden = true
                } else if (status === 'failed' || status === 'cancelled') {
                    actionsFooter.hidden = false
                    backLink.hidden = false
                    backLink.textContent = restartInstallLabel
                    adminLink.hidden = true
                } else {
                    actionsFooter.hidden = true
                    backLink.hidden = true
                    adminLink.hidden = true
                }
            }

            function renderLines(lines) {
                if (!lines || lines.length === 0) {
                    return
                }

                if (lines.length < renderedLineCount) {
                    logEl.innerHTML = ''
                    renderedLineCount = 0
                }

                if (renderedLineCount === 0) {
                    logEl.innerHTML = ''
                }

                lines.slice(renderedLineCount).forEach(function (entry) {
                    var div = document.createElement('div')
                    div.className = 'line ' + (entry.type || '')
                    div.textContent = entry.line || ''
                    logEl.appendChild(div)
                })

                renderedLineCount = lines.length
                logEl.scrollTop = logEl.scrollHeight
            }

            function poll() {
                if (stopped) {
                    return
                }
                fetch(endpoint, { headers: { Accept: 'application/json' } })
                    .then(function (response) {
                        return response.json()
                    })
                    .then(function (payload) {
                        if (payload.redirectUrl) {
                            window.location.href = payload.redirectUrl
                            return
                        }

                        applyStatus(payload.status)
                        renderLines(payload.lines)
                        if (
                            payload.status === 'complete' ||
                            payload.status === 'failed' ||
                            payload.status === 'cancelled'
                        ) {
                            stopped = true
                            return
                        }
                        setTimeout(poll, 1000)
                    })
                    .catch(function () {
                        setTimeout(poll, 2000)
                    })
            }

            poll()
        })()
    </script>
@endsection
