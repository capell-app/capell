@php
    use Capell\Admin\Data\Activity\ActivityFieldDiffData;

    /** @var list<ActivityFieldDiffData> $fieldDiffs */
    /** @var bool $isFirstVersion */
@endphp

<div class="space-y-4 text-sm">
    @if ($isFirstVersion)
        <p class="text-gray-600 dark:text-gray-300">{{ __('capell-admin::event-sourcing.compare_first_version') }}</p>
    @endif

    @if ($fieldDiffs === [])
        <p class="text-gray-600 dark:text-gray-300">{{ __('capell-admin::event-sourcing.compare_no_changes') }}</p>
    @else
        <div class="divide-y divide-gray-100 dark:divide-gray-800">
            @foreach ($fieldDiffs as $field)
                @php($rows = $field->nestedChanges === [] ? [$field] : $field->nestedChanges)
                @foreach ($rows as $row)
                    <div class="py-3">
                        <p class="font-medium text-gray-700 dark:text-gray-200">{{ $row->label }}</p>
                        <div class="mt-1 grid gap-2 sm:grid-cols-2">
                            <div class="rounded bg-gray-50 p-2 dark:bg-white/5">
                                <span
                                    class="block text-xs text-gray-500 uppercase dark:text-gray-300"
                                >
                                    {{ __('capell-admin::event-sourcing.compare_before') }}
                                </span>
                                <span
                                    class="break-words text-gray-700 dark:text-gray-200"
                                    >{{ $row->beforeSummary }}</span
                                >
                            </div>
                            <div
                                class="bg-primary-50 dark:bg-primary-900/20 rounded p-2"
                            >
                                <span
                                    class="block text-xs text-gray-500 uppercase dark:text-gray-300"
                                >
                                    {{ __('capell-admin::event-sourcing.compare_after') }}
                                </span>
                                <span
                                    class="break-words text-gray-700 dark:text-gray-200"
                                    >{{ $row->afterSummary }}</span
                                >
                            </div>
                        </div>
                    </div>
                @endforeach
                @if ($field->hiddenNestedChangeCount > 0)
                    <p class="py-2 text-xs text-gray-500 dark:text-gray-400">
                        {{ trans_choice('capell-admin::event-sourcing.compare_hidden_changes', $field->hiddenNestedChangeCount, ['count' => $field->hiddenNestedChangeCount]) }}
                    </p>
                @endif
            @endforeach
        </div>
    @endif
</div>
