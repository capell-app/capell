@php
    $latestAdvisorySnapshot = $this->latestAdvisorySnapshot();
    $securityAdvisories = $this->securityAdvisories();
    $bugAdvisories = $this->bugAdvisories();
    $updateNotices = $this->updateNotices();
    $summaryCounts = $this->updateSummaryCounts();
    $allNotices = collect($securityAdvisories)
        ->merge($bugAdvisories)
        ->merge($updateNotices)
        ->values();
    $readinessReport = $this->readinessReport();
    $currentUpgradeRun = $this->currentOrLastUpgradeRun();
    $recentUpgradeRunEvents = $this->recentUpgradeRunEvents($currentUpgradeRun);
@endphp

<x-filament-panels::page>
    <div class="space-y-5">
        <section
            class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900"
        >
            <div
                class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between"
            >
                <div>
                    <p
                        class="text-xs font-semibold text-gray-500 uppercase dark:text-gray-400"
                    >
                        {{ __('capell-admin::generic.update_center_status') }}
                    </p>
                    <h2
                        class="mt-1 text-xl font-semibold text-gray-950 dark:text-white"
                    >
                        {{
                            __('capell-admin::generic.current_to_target_version', [
                                'current' => $this->installedCapellVersion(),
                                'target' => $this->targetCapellVersion(),
                            ])
                        }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {{ $this->updateDistanceLabel() }}
                    </p>
                </div>

                <dl class="grid gap-2 sm:grid-cols-4 lg:min-w-[34rem]">
                    <div
                        class="rounded-md border border-gray-200 p-3 dark:border-white/10"
                    >
                        <dt class="text-xs text-gray-500 dark:text-gray-400">
                            {{ __('capell-admin::generic.security') }}
                        </dt>
                        <dd
                            class="text-danger-600 dark:text-danger-300 mt-1 text-lg font-semibold"
                        >
                            {{ $summaryCounts['security'] }}
                        </dd>
                    </div>
                    <div
                        class="rounded-md border border-gray-200 p-3 dark:border-white/10"
                    >
                        <dt class="text-xs text-gray-500 dark:text-gray-400">
                            {{ __('capell-admin::generic.bugfix') }}
                        </dt>
                        <dd class="mt-1 text-lg font-semibold">
                            {{ $summaryCounts['bugfix'] }}
                        </dd>
                    </div>
                    <div
                        class="rounded-md border border-gray-200 p-3 dark:border-white/10"
                    >
                        <dt class="text-xs text-gray-500 dark:text-gray-400">
                            {{ __('capell-admin::generic.feature') }}
                        </dt>
                        <dd class="mt-1 text-lg font-semibold">
                            {{ $summaryCounts['feature'] }}
                        </dd>
                    </div>
                    <div
                        class="rounded-md border border-gray-200 p-3 dark:border-white/10"
                    >
                        <dt class="text-xs text-gray-500 dark:text-gray-400">
                            {{ __('capell-admin::generic.major') }}
                        </dt>
                        <dd
                            class="text-warning-600 dark:text-warning-300 mt-1 text-lg font-semibold"
                        >
                            {{ $summaryCounts['major'] }}
                        </dd>
                    </div>
                </dl>
            </div>
        </section>

        <dl class="grid gap-3 md:grid-cols-4">
            <div
                class="rounded-lg border border-gray-200 p-3 dark:border-white/10"
            >
                <dt class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('capell-admin::generic.current_version') }}
                </dt>
                <dd class="mt-1 text-sm font-medium">
                    {{ $this->installedCapellVersion() }}
                </dd>
            </div>
            <div
                class="rounded-lg border border-gray-200 p-3 dark:border-white/10"
            >
                <dt class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('capell-admin::generic.target_version') }}
                </dt>
                <dd class="mt-1 text-sm font-medium">
                    {{ $this->targetCapellVersion() }}
                </dd>
            </div>
            <div
                class="rounded-lg border border-gray-200 p-3 dark:border-white/10"
            >
                <dt class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('capell-admin::generic.last_checked') }}
                </dt>
                <dd class="mt-1 text-sm font-medium">
                    {{ $latestAdvisorySnapshot?->checked_at?->diffForHumans() ?? __('capell-admin::generic.never') }}
                </dd>
            </div>
            <div
                class="rounded-lg border border-gray-200 p-3 dark:border-white/10"
            >
                <dt class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('capell-admin::generic.last_run') }}
                </dt>
                <dd class="mt-1 text-sm font-medium">
                    {{ $currentUpgradeRun?->status ? $this->runStatusLabel($currentUpgradeRun->status) : __('capell-admin::generic.not_run') }}
                </dd>
            </div>
        </dl>

        <section class="space-y-3">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-base font-semibold">
                    {{ __('capell-admin::generic.updates_to_review') }}
                </h2>
                <a
                    href="https://docs.capell.app/upgrading/"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-primary-700 dark:text-primary-300 text-sm font-medium underline-offset-4 hover:underline"
                >
                    {{ __('capell-admin::generic.upgrade_docs_link') }}
                </a>
            </div>

            @if ($allNotices->isEmpty())
                <div
                    class="rounded-lg border border-gray-200 p-4 dark:border-white/10"
                >
                    <p
                        class="text-sm font-medium text-gray-950 dark:text-white"
                    >
                        {{ __('capell-admin::generic.no_update_advisories') }}
                    </p>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {{
                            $latestAdvisorySnapshot === null
                            ? __('capell-admin::generic.no_update_advisories_first_check_description')
                            : __('capell-admin::generic.no_update_advisories_description')
                        }}
                    </p>
                </div>
            @else
                <div class="space-y-2">
                    @foreach ($allNotices as $notice)
                        <article
                            class="rounded-lg border border-gray-200 p-4 dark:border-white/10"
                        >
                            <div
                                class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between"
                            >
                                <div class="min-w-0">
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <span
                                            class="rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-700 uppercase dark:bg-white/10 dark:text-gray-200"
                                        >
                                            {{ __('capell-admin::generic.' . $this->noticeUpdateType($notice)) }}
                                        </span>
                                        <span
                                            class="text-xs text-gray-500 dark:text-gray-400"
                                        >
                                            {{ $this->noticeComposerNamesLabel($notice) }}
                                        </span>
                                        <span
                                            class="text-xs text-gray-500 dark:text-gray-400"
                                        >
                                            {{ $this->noticeVersionLine($notice) }}
                                        </span>
                                    </div>

                                    <h3
                                        class="mt-2 text-sm font-semibold text-gray-950 dark:text-white"
                                    >
                                        {{ data_get($notice, 'title', __('capell-admin::generic.package_update')) }}
                                    </h3>

                                    <p
                                        class="mt-1 text-sm leading-6 text-gray-600 dark:text-gray-400"
                                    >
                                        {{ data_get($notice, 'summary', __('capell-admin::generic.package_update_summary_missing')) }}
                                    </p>
                                </div>

                                <dl
                                    class="grid shrink-0 grid-cols-2 gap-3 text-sm md:w-64"
                                >
                                    <div>
                                        <dt
                                            class="text-xs text-gray-500 dark:text-gray-400"
                                        >
                                            {{ __('capell-admin::generic.estimated_impact') }}
                                        </dt>
                                        <dd class="mt-1 font-medium">
                                            {{ $this->noticeImpactLabel($notice) }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt
                                            class="text-xs text-gray-500 dark:text-gray-400"
                                        >
                                            {{ __('capell-admin::generic.backup') }}
                                        </dt>
                                        <dd class="mt-1 font-medium">
                                            {{ __('capell-admin::generic.recommended') }}
                                        </dd>
                                    </div>
                                </dl>
                            </div>

                            @if (data_get($notice, 'release_notes_url') || data_get($notice, 'upgrade_guide_url') || $this->noticeCanBeDismissed($notice))
                                <div class="mt-3 flex flex-wrap gap-3 text-sm">
                                    @if (data_get($notice, 'release_notes_url'))
                                        <a
                                            href="{{ data_get($notice, 'release_notes_url') }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="text-primary-700 dark:text-primary-300 font-medium underline-offset-4 hover:underline"
                                        >
                                            {{ __('capell-admin::generic.release_notes') }}
                                        </a>
                                    @endif

                                    @if (data_get($notice, 'upgrade_guide_url'))
                                        <a
                                            href="{{ data_get($notice, 'upgrade_guide_url') }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="text-primary-700 dark:text-primary-300 font-medium underline-offset-4 hover:underline"
                                        >
                                            {{ __('capell-admin::generic.upgrade_guide') }}
                                        </a>
                                    @endif

                                    @if ($this->noticeCanBeDismissed($notice))
                                        <button
                                            type="button"
                                            wire:click="dismissNotice(@js(data_get($notice, 'notice_id', data_get($notice, 'id', ''))))"
                                            class="text-primary-700 dark:text-primary-300 font-medium underline-offset-4 hover:underline"
                                        >
                                            {{ __('capell-admin::button.dismiss') }}
                                        </button>
                                    @endif
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        <section
            class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900"
        >
            <div class="space-y-3">
                <div>
                    <h2
                        class="text-sm font-semibold text-gray-950 dark:text-white"
                    >
                        {{ __('capell-admin::generic.manual_upgrade_command') }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('capell-admin::generic.manual_upgrade_command_hint') }}
                    </p>
                </div>

                <dl class="grid gap-3 md:grid-cols-2">
                    <div>
                        <dt class="text-xs text-gray-500 dark:text-gray-400">
                            {{ __('capell-admin::button.preview_changes') }}
                        </dt>
                        <dd
                            class="mt-1 overflow-auto rounded-md bg-gray-950 px-3 py-2 font-mono text-xs text-gray-100"
                        >
                            {{ $this->manualUpgradeCommand(dryRun: true) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500 dark:text-gray-400">
                            {{ __('capell-admin::button.run_safe_update') }}
                        </dt>
                        <dd
                            class="mt-1 overflow-auto rounded-md bg-gray-950 px-3 py-2 font-mono text-xs text-gray-100"
                        >
                            {{ $this->manualUpgradeCommand() }}
                        </dd>
                    </div>
                </dl>
            </div>
        </section>

        <section
            class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900"
        >
            <div
                class="flex flex-col gap-3 border-b border-gray-200 bg-gray-50/80 px-4 py-3 md:flex-row md:items-center md:justify-between dark:border-white/10 dark:bg-white/[0.03]"
            >
                <div class="flex min-w-0 items-baseline gap-3">
                    <h2
                        class="shrink-0 text-sm font-semibold text-gray-950 dark:text-white"
                    >
                        {{ __('capell-admin::generic.update_readiness') }}
                    </h2>
                    <p
                        class="truncate text-sm text-gray-600 dark:text-gray-400"
                    >
                        {{ __('capell-admin::generic.update_readiness_hint') }}
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <div class="hidden text-right sm:block">
                        <p
                            class="text-xs font-medium text-gray-500 uppercase dark:text-gray-400"
                        >
                            {{ __('capell-admin::generic.readiness_checks') }}
                        </p>
                        <p
                            class="text-sm font-semibold text-gray-950 dark:text-white"
                        >
                            {{ count($readinessReport->checks) }}
                        </p>
                    </div>
                    <div
                        role="status"
                        aria-live="polite"
                        @class([
                            'rounded-full px-2.5 py-1 text-xs font-semibold',
                            'bg-warning-100 text-warning-800 dark:bg-warning-400/10 dark:text-warning-200' => ! $readinessReport->canQueue(),
                            'bg-success-100 text-success-800 dark:bg-success-400/10 dark:text-success-200' => $readinessReport->canQueue(),
                        ])
                    >
                        {{ $readinessReport->canQueue() ? __('capell-admin::generic.ready_to_preview') : __('capell-admin::generic.manual_required') }}
                    </div>
                </div>
            </div>

            <ol
                class="grid gap-px bg-gray-200 md:grid-cols-2 xl:grid-cols-3 dark:bg-white/10"
            >
                @foreach ($readinessReport->checks as $stepIndex => $check)
                    <li class="relative bg-white px-4 py-3 dark:bg-gray-900">
                        <div class="flex items-start gap-2.5">
                            <div
                                class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-gray-100 text-xs font-semibold text-gray-700 dark:bg-white/10 dark:text-gray-200"
                            >
                                {{ str_pad((string) ($stepIndex + 1), 2, '0', STR_PAD_LEFT) }}
                            </div>
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3
                                        class="text-sm font-semibold text-gray-950 dark:text-white"
                                    >
                                        {{ str($check->key)->replace(['_', ':'], ' ')->headline() }}
                                    </h3>
                                    <span
                                        @class([
                                            'rounded-full px-2 py-0.5 text-xs font-semibold ring-1',
                                            'bg-success-50 text-success-800 ring-success-600/20 dark:bg-success-400/10 dark:text-success-200 dark:ring-success-300/20' => $check->passed,
                                            'bg-warning-50 text-warning-800 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-200 dark:ring-warning-300/20' => ! $check->passed && ! $check->blocking,
                                            'bg-danger-50 text-danger-800 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-200 dark:ring-danger-300/20' => ! $check->passed && $check->blocking,
                                        ])
                                    >
                                        {{ $check->passed ? __('capell-admin::generic.passed') : ($check->blocking ? __('capell-admin::generic.blocked') : __('capell-admin::generic.warning')) }}
                                    </span>
                                </div>
                                <p
                                    class="mt-1 text-xs leading-5 text-gray-600 dark:text-gray-400"
                                >
                                    {{ $check->message }}
                                </p>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>

        @if ($currentUpgradeRun instanceof UpgradeRun)
            <section
                class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900"
            >
                <div
                    class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between"
                >
                    <div>
                        <h2
                            class="text-sm font-semibold text-gray-950 dark:text-white"
                        >
                            {{ __('capell-admin::generic.upgrade_run_timeline') }}
                        </h2>
                        <p
                            class="mt-1 text-sm text-gray-600 dark:text-gray-400"
                        >
                            {{ __('capell-admin::generic.upgrade_run_status', ['status' => $this->runStatusLabel($currentUpgradeRun->status)]) }}
                        </p>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        #{{ $currentUpgradeRun->getKey() }}
                    </p>
                </div>

                <ol class="mt-4 space-y-3">
                    @forelse ($recentUpgradeRunEvents as $event)
                        <li
                            class="border-l-2 border-gray-200 pl-3 dark:border-white/10"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="text-xs font-semibold text-gray-500 uppercase dark:text-gray-400"
                                >
                                    {{ $this->eventLevelLabel($event->level) }}
                                </span>
                                @if ($event->stage !== null)
                                    <span
                                        class="text-xs text-gray-500 dark:text-gray-400"
                                    >
                                        {{ $this->upgradeStageLabel($event->stage) }}
                                    </span>
                                @endif

                                <span
                                    class="text-xs text-gray-500 dark:text-gray-400"
                                >
                                    {{ $event->occurred_at->diffForHumans() }}
                                </span>
                            </div>
                            <p
                                class="mt-1 text-sm text-gray-700 dark:text-gray-300"
                            >
                                {{ $event->message }}
                            </p>
                        </li>
                    @empty
                        <li class="text-sm text-gray-600 dark:text-gray-400">
                            {{ __('capell-admin::generic.no_upgrade_run_events') }}
                        </li>
                    @endforelse
                </ol>
            </section>
        @endif

        @if ($this->lastOutput !== null)
            <details
                role="status"
                aria-live="polite"
                class="rounded-lg border border-gray-200 p-4 dark:border-white/10"
            >
                <summary class="cursor-pointer text-sm font-semibold">
                    {{ __('capell-admin::generic.developer_log') }}
                </summary>

                <pre
                    class="mt-3 max-h-96 overflow-auto rounded-lg bg-gray-950 p-4 text-sm text-gray-100"
                >
{{ $this->lastOutput }}</pre>
            </details>
        @endif
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
