@php
    $fieldDiffs = collect($fieldDiffs);
    $emptyMessageKey = $changeSet->emptyMessage ?? 'capell-admin::activity.no_change_details';
    $emptyDescriptionKey = $emptyMessageKey === 'capell-admin::activity.no_field_changes'
        ? 'capell-admin::activity.no_change_details'
        : $emptyMessageKey;

    $statusBadgeClass = static fn (string $status): string => match ($status) {
        'created' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-400/30',
        'deleted' => 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-400/30',
        default => 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/30',
    };
@endphp

<div
    class="space-y-6"
    data-capell-activity-details
>
    <section
        class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-950"
    >
        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
        >
            <div class="space-y-1">
                <p class="text-sm font-semibold text-gray-950 dark:text-white">
                    {{ $changeSet->summary }}
                </p>
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    {{ $changeSet->resource?->label ?? __('capell-admin::activity.subject_missing') }}
                </p>
            </div>

            <div class="flex flex-wrap gap-2 text-xs">
                <span
                    class="inline-flex items-center rounded-md bg-gray-50 px-2 py-1 font-medium text-gray-700 ring-1 ring-gray-600/10 ring-inset dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-500/20"
                >
                    {{ $changeSet->event ?? __('capell-admin::generic.unknown') }}
                </span>

                @if ($changeSet->resource !== null)
                    <span
                        class="inline-flex items-center rounded-md bg-gray-50 px-2 py-1 font-medium text-gray-700 ring-1 ring-gray-600/10 ring-inset dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-500/20"
                    >
                        {{ trans_choice('capell-admin::activity.changed_field_count', $changeSet->resource->changedFieldCount, ['count' => $changeSet->resource->changedFieldCount]) }}
                    </span>
                @endif
            </div>
        </div>

        <dl
            class="mt-4 grid gap-3 border-t border-gray-200 pt-4 text-sm text-gray-600 sm:grid-cols-2 dark:border-gray-800 dark:text-gray-300"
        >
            <div>
                <dt class="font-medium text-gray-950 dark:text-white">
                    {{ __('capell-admin::dashboard.activity_actor') }}
                </dt>
                <dd>{{ $changeSet->actorLabel }}</dd>
            </div>
            <div>
                <dt class="font-medium text-gray-950 dark:text-white">
                    {{ __('capell-admin::table.created_at') }}
                </dt>
                <dd>{{ $changeSet->occurredAt?->toDayDateTimeString() }}</dd>
            </div>
            @if ($changeSet->workspaceId !== null)
                <div>
                    <dt class="font-medium text-gray-950 dark:text-white">
                        {{ __('capell-admin::activity.workspace') }}
                    </dt>
                    <dd>{{ $changeSet->workspaceId }}</dd>
                </div>
            @endif

            @if ($changeSet->resource?->area !== null)
                <div>
                    <dt class="font-medium text-gray-950 dark:text-white">
                        {{ __('capell-admin::activity.area') }}
                    </dt>
                    <dd>{{ $changeSet->resource->area }}</dd>
                </div>
            @endif

            @if ($changeSet->resource?->package !== null)
                <div>
                    <dt class="font-medium text-gray-950 dark:text-white">
                        {{ __('capell-admin::activity.package') }}
                    </dt>
                    <dd>{{ $changeSet->resource->package }}</dd>
                </div>
            @endif

            @if ($changeSet->resource?->stableIdentifier !== null)
                <div>
                    <dt class="font-medium text-gray-950 dark:text-white">
                        {{ __('capell-admin::activity.identifier') }}
                    </dt>
                    <dd class="break-all">
                        {{ $changeSet->resource->stableIdentifier }}
                    </dd>
                </div>
            @endif
        </dl>
    </section>

    @if ($fieldDiffs->isNotEmpty())
        <section class="space-y-3">
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">
                {{ __('capell-admin::activity.changes') }}
            </h3>

            @foreach ($fieldDiffs as $fieldDiff)
                <article
                    class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-950"
                >
                    <header
                        class="flex flex-col gap-3 border-b border-gray-200 bg-gray-50 px-4 py-3 sm:flex-row sm:items-start sm:justify-between dark:border-gray-800 dark:bg-gray-900"
                    >
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h4
                                    class="text-sm font-semibold text-gray-950 dark:text-white"
                                >
                                    {{ $fieldDiff->label }}
                                </h4>
                                <span
                                    class="{{ $statusBadgeClass($fieldDiff->status) }} inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset"
                                >
                                    {{ __('capell-admin::activity.statuses.' . $fieldDiff->status) }}
                                </span>
                            </div>

                            @if ($fieldDiff->label !== $fieldDiff->path)
                                <p
                                    class="mt-1 text-xs break-all text-gray-500 dark:text-gray-400"
                                >
                                    {{ $fieldDiff->path }}
                                </p>
                            @endif
                        </div>

                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            @if ($fieldDiff->reversible)
                                {{ __('capell-admin::activity.revertible') }}
                            @elseif ($fieldDiff->skipReason !== null)
                                {{ __('capell-admin::activity.skip_reasons.' . $fieldDiff->skipReason) }}
                            @else
                                {{ __('capell-admin::generic.not_available') }}
                            @endif
                        </div>
                    </header>

                    <div class="p-4">
                        @if ($fieldDiff->nestedChanges !== [])
                            <div
                                class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800"
                            >
                                <table
                                    class="w-full divide-y divide-gray-200 text-sm dark:divide-gray-800"
                                >
                                    <thead class="bg-gray-50 dark:bg-gray-900">
                                        <tr>
                                            <th
                                                class="w-2/5 px-3 py-2 text-left font-medium text-gray-950 dark:text-white"
                                            >
                                                {{ __('capell-admin::activity.changed_inside') }}
                                            </th>
                                            <th
                                                class="px-3 py-2 text-left font-medium text-gray-950 dark:text-white"
                                            >
                                                {{ __('capell-admin::table.old_value') }}
                                            </th>
                                            <th
                                                class="px-3 py-2 text-left font-medium text-gray-950 dark:text-white"
                                            >
                                                {{ __('capell-admin::table.new_value') }}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody
                                        class="divide-y divide-gray-200 dark:divide-gray-800"
                                    >
                                        @foreach ($fieldDiff->nestedChanges as $nestedChange)
                                            <tr>
                                                <td class="px-3 py-3 align-top">
                                                    <div
                                                        class="font-medium text-gray-950 dark:text-white"
                                                    >
                                                        {{ $nestedChange->label }}
                                                    </div>
                                                    <div
                                                        class="mt-1 text-xs break-all text-gray-500 dark:text-gray-400"
                                                    >
                                                        {{ $nestedChange->path }}
                                                    </div>
                                                </td>
                                                <td
                                                    class="px-3 py-3 align-top text-gray-700 dark:text-gray-300"
                                                >
                                                    {{ $nestedChange->beforeSummary }}
                                                </td>
                                                <td
                                                    class="px-3 py-3 align-top text-gray-700 dark:text-gray-300"
                                                >
                                                    {{ $nestedChange->afterSummary }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            @if ($fieldDiff->hiddenNestedChangeCount > 0)
                                <p
                                    class="mt-2 text-xs text-gray-500 dark:text-gray-400"
                                >
                                    {{ trans_choice('capell-admin::activity.more_nested_changes', $fieldDiff->hiddenNestedChangeCount, ['count' => $fieldDiff->hiddenNestedChangeCount]) }}
                                </p>
                            @endif
                        @else
                            <div class="grid gap-3 md:grid-cols-2">
                                <div
                                    class="rounded-lg bg-rose-50/70 p-3 ring-1 ring-rose-600/10 dark:bg-rose-500/10 dark:ring-rose-400/20"
                                >
                                    <div
                                        class="text-xs font-medium text-rose-700 dark:text-rose-300"
                                    >
                                        {{ __('capell-admin::table.old_value') }}
                                    </div>
                                    <div
                                        class="mt-2 text-sm break-words text-gray-950 dark:text-gray-100"
                                    >
                                        {{ $fieldDiff->beforeSummary }}
                                    </div>
                                </div>

                                <div
                                    class="rounded-lg bg-emerald-50/70 p-3 ring-1 ring-emerald-600/10 dark:bg-emerald-500/10 dark:ring-emerald-400/20"
                                >
                                    <div
                                        class="text-xs font-medium text-emerald-700 dark:text-emerald-300"
                                    >
                                        {{ __('capell-admin::table.new_value') }}
                                    </div>
                                    <div
                                        class="mt-2 text-sm break-words text-gray-950 dark:text-gray-100"
                                    >
                                        {{ $fieldDiff->afterSummary }}
                                    </div>
                                </div>
                            </div>
                        @endif

                        @if ($fieldDiff->beforeDetail !== $fieldDiff->beforeSummary || $fieldDiff->afterDetail !== $fieldDiff->afterSummary)
                            <details
                                class="mt-3 rounded-lg border border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-gray-900"
                            >
                                <summary
                                    class="cursor-pointer px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-200"
                                >
                                    {{ __('capell-admin::activity.full_value') }}
                                </summary>

                                <div
                                    class="grid gap-3 border-t border-gray-200 p-3 md:grid-cols-2 dark:border-gray-800"
                                >
                                    <div class="space-y-2">
                                        <div
                                            class="text-xs font-medium text-gray-500 dark:text-gray-400"
                                        >
                                            {{ __('capell-admin::table.old_value') }}
                                        </div>
                                        <pre
                                            class="max-h-64 overflow-auto rounded-md bg-white p-3 text-xs break-words whitespace-pre-wrap text-gray-700 dark:bg-gray-950 dark:text-gray-300"
                                        >
{{ $fieldDiff->beforeDetail }}</pre>
                                    </div>

                                    <div class="space-y-2">
                                        <div
                                            class="text-xs font-medium text-gray-500 dark:text-gray-400"
                                        >
                                            {{ __('capell-admin::table.new_value') }}
                                        </div>
                                        <pre
                                            class="max-h-64 overflow-auto rounded-md bg-white p-3 text-xs break-words whitespace-pre-wrap text-gray-700 dark:bg-gray-950 dark:text-gray-300"
                                        >
{{ $fieldDiff->afterDetail }}</pre>
                                    </div>
                                </div>
                            </details>
                        @endif
                    </div>
                </article>
            @endforeach
        </section>
    @else
        <section
            class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-950"
        >
            <p class="text-sm font-medium text-gray-950 dark:text-white">
                {{ __('capell-admin::activity.no_field_changes') }}
            </p>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                {{ __($emptyDescriptionKey) }}
            </p>
        </section>
    @endif
</div>
