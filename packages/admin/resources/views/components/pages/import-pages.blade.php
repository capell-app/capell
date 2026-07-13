<x-filament-panels::page>
    @if ($step === 'upload')
        <form wire:submit="parseAndAdvance">
            {{ $this->form }}

            <div class="mt-6">
                <x-filament::button type="submit">
                    {{ __('capell-admin::exchanger.advance_button') }}
                </x-filament::button>
            </div>
        </form>
    @elseif ($step === 'review')
        <div class="space-y-4">
            <div>
                <h2 class="text-lg font-semibold">
                    {{ __('capell-admin::exchanger.review_heading') }}
                </h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('capell-admin::exchanger.review_description') }}
                </p>
            </div>

            <div
                class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700"
            >
                <table
                    class="min-w-full divide-y divide-gray-200 dark:divide-gray-700"
                >
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th
                                class="px-3 py-2 text-left text-xs font-medium tracking-wider uppercase"
                            >
                                {{ __('capell-admin::exchanger.column_title') }}
                            </th>
                            <th
                                class="px-3 py-2 text-left text-xs font-medium tracking-wider uppercase"
                            >
                                {{ __('capell-admin::exchanger.column_url') }}
                            </th>
                            <th
                                class="px-3 py-2 text-left text-xs font-medium tracking-wider uppercase"
                            >
                                {{ __('capell-admin::exchanger.column_site') }}
                            </th>
                            <th
                                class="px-3 py-2 text-left text-xs font-medium tracking-wider uppercase"
                            >
                                {{ __('capell-admin::exchanger.column_collision') }}
                            </th>
                            <th
                                class="px-3 py-2 text-left text-xs font-medium tracking-wider uppercase"
                            >
                                {{ __('capell-admin::exchanger.column_action') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-900"
                    >
                        @foreach ($reviewRows as $reviewRow)
                            @php
                                $rowUuid = $reviewRow['uuid'];
                                $collisionState = $reviewRow['collision_state'];
                                $badgeClasses = match ($collisionState) {
                                    'url_conflict_live' => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200',
                                    'url_conflict_workspace' => 'bg-rose-100 text-rose-800 dark:bg-rose-900 dark:text-rose-200',
                                    default => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200',
                                };
                                $collisionLabel = match ($collisionState) {
                                    'url_conflict_live' => __('capell-admin::exchanger.collision_url_live'),
                                    'url_conflict_workspace' => __('capell-admin::exchanger.collision_url_workspace'),
                                    default => __('capell-admin::exchanger.collision_none'),
                                };
                            @endphp

                            <tr>
                                <td class="px-3 py-2 text-sm">
                                    {{ $reviewRow['title'] ?? '—' }}
                                </td>
                                <td class="px-3 py-2 font-mono text-sm">
                                    {{ $reviewRow['primary_url'] ?? '—' }}
                                </td>
                                <td class="px-3 py-2 text-sm">
                                    {{ $reviewRow['site_ref'] ?? '—' }}
                                    @if (! empty($reviewRow['resolved_site_id']))
                                        <span class="text-xs text-gray-500">
                                            #{{ $reviewRow['resolved_site_id'] }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-sm">
                                    <span
                                        class="{{ $badgeClasses }} inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                    >
                                        {{ $collisionLabel }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-sm">
                                    <select
                                        wire:model.live="pageDecisions.{{ $rowUuid }}.action"
                                        class="block w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800"
                                    >
                                        <option value="create">
                                            {{ __('capell-admin::exchanger.action_create') }}
                                        </option>
                                        <option value="update">
                                            {{ __('capell-admin::exchanger.action_update') }}
                                        </option>
                                        <option value="skip">
                                            {{ __('capell-admin::exchanger.action_skip') }}
                                        </option>
                                    </select>
                                </td>
                            </tr>
                        @endforeach

                        @if (empty($reviewRows))
                            <tr>
                                <td
                                    colspan="5"
                                    class="px-3 py-6 text-center text-sm text-gray-500"
                                >
                                    {{ __('capell-admin::exchanger.review_empty') }}
                                </td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>

            <div class="flex items-center gap-3">
                <x-filament::button wire:click="advanceToResolve">
                    {{ __('capell-admin::exchanger.run_import') }}
                </x-filament::button>

                <x-filament::button
                    color="gray"
                    wire:click="backToUpload"
                >
                    {{ __('capell-admin::exchanger.back_button') }}
                </x-filament::button>
            </div>
        </div>
    @elseif ($step === 'resolve')
        @php
            $canUpdateShared = $this->canUpdateSharedRelations();
            $groupedRows = collect($resolveRows)->groupBy('group');
            $groupLabels = [
                'layouts' => __('capell-admin::exchanger.group_layouts'),
                'blueprints' => __('capell-admin::exchanger.group_types'),
                'sites' => __('capell-admin::exchanger.group_sites'),
                'media' => __('capell-admin::exchanger.group_media'),
            ];
        @endphp

        <div class="space-y-6">
            <div>
                <h2 class="text-lg font-semibold">
                    {{ __('capell-admin::exchanger.resolve_heading') }}
                </h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('capell-admin::exchanger.resolve_description') }}
                </p>
            </div>

            @foreach ($groupedRows as $groupKey => $rows)
                <div class="space-y-2">
                    <h3
                        class="text-sm font-semibold tracking-wider text-gray-700 uppercase dark:text-gray-200"
                    >
                        {{ $groupLabels[$groupKey] ?? $groupKey }}
                    </h3>

                    <div
                        class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700"
                    >
                        <table
                            class="min-w-full divide-y divide-gray-200 dark:divide-gray-700"
                        >
                            <thead class="bg-gray-50 dark:bg-gray-800">
                                <tr>
                                    <th
                                        class="px-3 py-2 text-left text-xs font-medium tracking-wider uppercase"
                                    >
                                        {{ __('capell-admin::exchanger.column_ref') }}
                                    </th>
                                    <th
                                        class="px-3 py-2 text-left text-xs font-medium tracking-wider uppercase"
                                    >
                                        {{ __('capell-admin::exchanger.column_match') }}
                                    </th>
                                    <th
                                        class="px-3 py-2 text-left text-xs font-medium tracking-wider uppercase"
                                    >
                                        {{ __('capell-admin::exchanger.column_alternatives') }}
                                    </th>
                                    <th
                                        class="px-3 py-2 text-left text-xs font-medium tracking-wider uppercase"
                                    >
                                        {{ __('capell-admin::exchanger.column_action') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody
                                class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-900"
                            >
                                @foreach ($rows as $resolveRow)
                                    @php
                                        $rowRef = $resolveRow['ref'];
                                        $topMatch = $resolveRow['top_match'] ?? null;
                                        $alternatives = $resolveRow['alternatives'] ?? [];
                                        $currentAction = $relationDecisions[$rowRef]['action'] ?? 'use_existing';
                                        $isUpdateExisting = $currentAction === 'update_existing';
                                    @endphp

                                    <tr>
                                        <td class="px-3 py-2 font-mono text-sm">
                                            {{ $rowRef }}
                                        </td>
                                        <td class="px-3 py-2 text-sm">
                                            @if ($topMatch)
                                                <div class="font-medium">
                                                    #{{ $topMatch['local_id'] }}
                                                </div>
                                                <div
                                                    class="text-xs text-gray-500"
                                                >
                                                    {{ $topMatch['strategy'] }}
                                                    ·
                                                    {{ number_format((float) $topMatch['confidence'] * 100, 0) }}%
                                                </div>
                                                @if (! empty($topMatch['reason']))
                                                    <div
                                                        class="text-xs text-gray-400"
                                                    >
                                                        {{ $topMatch['reason'] }}
                                                    </div>
                                                @endif
                                            @else
                                                <span
                                                    class="text-xs text-gray-500 italic"
                                                >
                                                    —
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 text-sm">
                                            @if (! empty($alternatives))
                                                <ul class="space-y-1">
                                                    @foreach ($alternatives as $alternative)
                                                        <li
                                                            class="text-xs text-gray-600 dark:text-gray-300"
                                                        >
                                                            #{{ $alternative['local_id'] }}
                                                            ·
                                                            {{ $alternative['strategy'] }}
                                                            ·
                                                            {{ number_format((float) $alternative['confidence'] * 100, 0) }}%
                                                            @if (! empty($alternative['reason']))
                                                                <span
                                                                    class="text-gray-400"
                                                                >
                                                                    ({{ $alternative['reason'] }})
                                                                </span>
                                                            @endif
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @else
                                                <span
                                                    class="text-xs text-gray-500 italic"
                                                >
                                                    —
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 text-sm">
                                            <select
                                                wire:model.live="relationDecisions.{{ $rowRef }}.action"
                                                @if ($groupKey === 'layouts' && $isUpdateExisting)
                                                    wire:confirm="{{ __('capell-admin::exchanger.update_existing_confirm_body') }}"
                                                @endif
                                                class="block w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800"
                                            >
                                                @if ($topMatch)
                                                    <option
                                                        value="use_existing"
                                                    >
                                                        {{ __('capell-admin::exchanger.action_use_existing') }}
                                                    </option>
                                                @endif

                                                <option value="create_new">
                                                    {{ __('capell-admin::exchanger.action_create_new') }}
                                                </option>
                                                <option value="clone_imported">
                                                    {{ __('capell-admin::exchanger.action_clone_imported') }}
                                                </option>
                                                @if ($canUpdateShared && $topMatch)
                                                    <option
                                                        value="update_existing"
                                                    >
                                                        {{ __('capell-admin::exchanger.action_update_existing') }}
                                                    </option>
                                                @endif

                                                <option value="skip">
                                                    {{ __('capell-admin::exchanger.action_skip') }}
                                                </option>
                                            </select>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach

            <div class="flex items-center gap-3">
                <x-filament::button wire:click="advanceToValidate">
                    {{ __('capell-admin::exchanger.advance_button_validate') }}
                </x-filament::button>

                <x-filament::button
                    color="gray"
                    wire:click="backToReview"
                >
                    {{ __('capell-admin::exchanger.back_button') }}
                </x-filament::button>
            </div>
        </div>
    @elseif ($step === 'validate')
        @php
            $pagesBuckets = $validationSummary['pages'] ?? ['create' => 0, 'update' => 0, 'skip' => 0];
            $relationsBuckets = $validationSummary['relations'] ?? ['match' => 0, 'create' => 0, 'clone' => 0, 'update' => 0, 'skip' => 0];
            $mediaBuckets = $validationSummary['media'] ?? ['import' => 0, 'reuse' => 0];
            $blockingErrors = $validationSummary['blocking_errors'] ?? [];
            $warningEntries = $validationSummary['warnings'] ?? [];
            $confirmationMatchesExpected = $this->confirmationMatches();
            $hasBlockingErrors = ! empty($blockingErrors);
        @endphp

        <div class="space-y-6">
            <div>
                <h2 class="text-lg font-semibold">
                    {{ __('capell-admin::exchanger.validate_heading') }}
                </h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('capell-admin::exchanger.validate_description') }}
                </p>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div
                    class="rounded-lg border border-gray-200 p-4 dark:border-gray-700"
                >
                    <h3 class="text-sm font-semibold tracking-wider uppercase">
                        {{ __('capell-admin::exchanger.summary_pages') }}
                    </h3>
                    <dl class="mt-2 space-y-1 text-sm">
                        <div class="flex justify-between">
                            <dt>
                                {{ __('capell-admin::exchanger.summary_create') }}
                            </dt>
                            <dd class="font-mono">
                                {{ $pagesBuckets['create'] ?? 0 }}
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt>
                                {{ __('capell-admin::exchanger.summary_update') }}
                            </dt>
                            <dd class="font-mono">
                                {{ $pagesBuckets['update'] ?? 0 }}
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt>
                                {{ __('capell-admin::exchanger.summary_skip') }}
                            </dt>
                            <dd class="font-mono">
                                {{ $pagesBuckets['skip'] ?? 0 }}
                            </dd>
                        </div>
                    </dl>
                </div>

                <div
                    class="rounded-lg border border-gray-200 p-4 dark:border-gray-700"
                >
                    <h3 class="text-sm font-semibold tracking-wider uppercase">
                        {{ __('capell-admin::exchanger.summary_relations') }}
                    </h3>
                    <dl class="mt-2 space-y-1 text-sm">
                        <div class="flex justify-between">
                            <dt>
                                {{ __('capell-admin::exchanger.summary_match') }}
                            </dt>
                            <dd class="font-mono">
                                {{ $relationsBuckets['match'] ?? 0 }}
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt>
                                {{ __('capell-admin::exchanger.summary_create') }}
                            </dt>
                            <dd class="font-mono">
                                {{ $relationsBuckets['create'] ?? 0 }}
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt>
                                {{ __('capell-admin::exchanger.summary_clone') }}
                            </dt>
                            <dd class="font-mono">
                                {{ $relationsBuckets['clone'] ?? 0 }}
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt>
                                {{ __('capell-admin::exchanger.summary_update') }}
                            </dt>
                            <dd class="font-mono">
                                {{ $relationsBuckets['update'] ?? 0 }}
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt>
                                {{ __('capell-admin::exchanger.summary_skip') }}
                            </dt>
                            <dd class="font-mono">
                                {{ $relationsBuckets['skip'] ?? 0 }}
                            </dd>
                        </div>
                    </dl>
                </div>

                <div
                    class="rounded-lg border border-gray-200 p-4 dark:border-gray-700"
                >
                    <h3 class="text-sm font-semibold tracking-wider uppercase">
                        {{ __('capell-admin::exchanger.summary_media') }}
                    </h3>
                    <dl class="mt-2 space-y-1 text-sm">
                        <div class="flex justify-between">
                            <dt>
                                {{ __('capell-admin::exchanger.summary_import') }}
                            </dt>
                            <dd class="font-mono">
                                {{ $mediaBuckets['import'] ?? 0 }}
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt>
                                {{ __('capell-admin::exchanger.summary_reuse') }}
                            </dt>
                            <dd class="font-mono">
                                {{ $mediaBuckets['reuse'] ?? 0 }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            @if ($hasBlockingErrors)
                <div
                    class="rounded-lg border border-rose-200 bg-rose-50 p-4 dark:border-rose-900 dark:bg-rose-950"
                >
                    <h3
                        class="text-sm font-semibold text-rose-800 dark:text-rose-200"
                    >
                        {{ __('capell-admin::exchanger.summary_blocking_errors') }}
                    </h3>
                    <ul
                        class="mt-2 list-disc space-y-1 pl-6 text-sm text-rose-700 dark:text-rose-200"
                    >
                        @foreach ($blockingErrors as $blockingMessage)
                            <li>{{ $blockingMessage }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (! empty($warningEntries))
                <div
                    class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950"
                >
                    <h3
                        class="text-sm font-semibold text-amber-800 dark:text-amber-200"
                    >
                        {{ __('capell-admin::exchanger.summary_warnings') }}
                    </h3>
                    <ul
                        class="mt-2 list-disc space-y-1 pl-6 text-sm text-amber-700 dark:text-amber-200"
                    >
                        @foreach ($warningEntries as $warningMessage)
                            <li>{{ $warningMessage }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="space-y-2">
                <label
                    for="import-confirmation"
                    class="block text-sm font-medium"
                >
                    {{ __('capell-admin::exchanger.confirmation_label') }}
                </label>
                <input
                    id="import-confirmation"
                    type="text"
                    wire:model.live="confirmation"
                    aria-describedby="import-confirmation-helper"
                    class="block w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800"
                />
                <p
                    id="import-confirmation-helper"
                    class="text-xs text-gray-500"
                >
                    {{ __('capell-admin::exchanger.confirmation_helper', ['expected' => $confirmationExpected]) }}
                </p>
            </div>

            @if ($hasBlockingErrors)
                <p
                    id="import-dispatch-blocked-helper"
                    class="text-sm text-rose-700 dark:text-rose-200"
                >
                    {{ __('capell-admin::exchanger.summary_blocking_errors_instruction') }}
                </p>
            @endif

            <div class="flex items-center gap-3">
                <x-filament::button
                    wire:click="dispatchImport"
                    :disabled="$hasBlockingErrors || ! $confirmationMatchesExpected"
                    @if ($hasBlockingErrors)
                    aria-describedby="import-dispatch-blocked-helper"
                    @endif
                >
                    {{ __('capell-admin::exchanger.dispatch_button') }}
                </x-filament::button>

                <x-filament::button
                    color="gray"
                    wire:click="backToResolve"
                >
                    {{ __('capell-admin::exchanger.back_button') }}
                </x-filament::button>
            </div>
        </div>
    @elseif ($step === 'executing')
        @php
            $progressPercent = $this->getProgressPercent();
            $statusKey = $sessionStatus ?? 'queued';
            $statusLabel = match ($statusKey) {
                'queued' => __('capell-admin::exchanger.status_queued'),
                'running' => __('capell-admin::exchanger.status_running'),
                'completed' => __('capell-admin::exchanger.status_completed'),
                'failed' => __('capell-admin::exchanger.status_failed'),
                default => $statusKey,
            };
        @endphp

        <div
            class="space-y-4"
            wire:poll.2s="refreshStatus"
        >
            <div>
                <h2 class="text-lg font-semibold">
                    {{ __('capell-admin::exchanger.execute_heading') }}
                </h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('capell-admin::exchanger.execute_description') }}
                </p>
            </div>

            <div class="flex items-center gap-3 text-sm">
                <svg
                    class="text-primary-600 size-4 animate-spin"
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                >
                    <circle
                        class="opacity-25"
                        cx="12"
                        cy="12"
                        r="10"
                        stroke="currentColor"
                        stroke-width="4"
                    ></circle>
                    <path
                        class="opacity-75"
                        fill="currentColor"
                        d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"
                    ></path>
                </svg>
                <span class="font-medium">{{ $statusLabel }}</span>
            </div>

            <div>
                <div class="mb-1 flex justify-between text-xs text-gray-500">
                    <span>
                        {{ __('capell-admin::exchanger.progress_label') }}
                    </span>
                    <span class="font-mono">{{ $progressPercent }}%</span>
                </div>
                <div
                    class="h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700"
                    role="progressbar"
                    aria-label="{{ __('capell-admin::exchanger.progress_label') }}"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    aria-valuenow="{{ $progressPercent }}"
                >
                    <div
                        class="bg-primary-600 h-full transition-all"
                        style="width: {{ $progressPercent }}%"
                    ></div>
                </div>
            </div>
        </div>
    @elseif ($step === 'completed')
        @php
            $workspaceUrl = $this->getTargetWorkspaceUrl();
            $pagesImported = $resultSummary['pages_imported'] ?? ($resultSummary['pages'] ?? 0);
            $relationsResolved = $resultSummary['relations_resolved'] ?? ($resultSummary['relations'] ?? 0);
            $mediaIngested = $resultSummary['media_ingested'] ?? ($resultSummary['media'] ?? 0);
        @endphp

        <div
            class="space-y-4 rounded-lg border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950"
        >
            <h2 class="text-lg font-semibold">
                {{ __('capell-admin::exchanger.status_completed') }}
            </h2>

            <dl class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-xs text-gray-500 uppercase">
                        {{ __('capell-admin::exchanger.result_pages_imported') }}
                    </dt>
                    <dd class="font-mono text-lg">{{ $pagesImported }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500 uppercase">
                        {{ __('capell-admin::exchanger.result_relations_resolved') }}
                    </dt>
                    <dd class="font-mono text-lg">{{ $relationsResolved }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500 uppercase">
                        {{ __('capell-admin::exchanger.result_media_ingested') }}
                    </dt>
                    <dd class="font-mono text-lg">{{ $mediaIngested }}</dd>
                </div>
            </dl>

            <div class="flex items-center gap-3">
                @if ($workspaceUrl)
                    <x-filament::button
                        tag="a"
                        :href="$workspaceUrl"
                    >
                        {{ __('capell-admin::exchanger.view_workspace_button') }}
                    </x-filament::button>
                @endif

                <x-filament::button
                    color="gray"
                    wire:click="backToUpload"
                >
                    {{ __('capell-admin::exchanger.start_new_import') }}
                </x-filament::button>
            </div>
        </div>
    @elseif ($step === 'failed')
        <div
            class="space-y-4 rounded-lg border border-rose-200 bg-rose-50 p-4 dark:border-rose-900 dark:bg-rose-950"
        >
            <h2 class="text-lg font-semibold text-rose-800 dark:text-rose-200">
                {{ __('capell-admin::exchanger.import_failed_title') }}
            </h2>
            <p class="text-sm text-rose-700 dark:text-rose-200">
                {{ __('capell-admin::exchanger.import_failed_body') }}
            </p>
            @if (! empty($failureReason))
                <pre
                    class="overflow-auto rounded bg-rose-100 p-3 text-xs text-rose-900 dark:bg-rose-900/40 dark:text-rose-100"
                >
{{ $failureReason }}</pre>
            @endif

            <x-filament::button wire:click="backToUpload">
                {{ __('capell-admin::exchanger.restart_wizard_button') }}
            </x-filament::button>
        </div>
    @endif

    <x-filament-actions::modals />
</x-filament-panels::page>
