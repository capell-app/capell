@php
    use Capell\Admin\Data\Activity\ActivityFieldDiffData;
    use Capell\Admin\Enums\RollbackDirection;
    use Capell\Core\EventSourcing\Rollback\RollbackPreviewData;

    /** @var RollbackPreviewData $preview */
    /** @var list<ActivityFieldDiffData> $fieldDiffs */
    /** @var RollbackDirection|null $direction */
    $direction ??= RollbackDirection::Back;
    $blockingIssues = $preview->blockingIssues();
    $warnings = $preview->warnings();
@endphp

<div class="space-y-4 text-sm">
    <p class="text-gray-600 dark:text-gray-300">
        {{
            __($direction->previewIntroKey(), [
                'version' => $targetVersion,
                'actor' => $targetActor ?? __('capell-admin::event-sourcing.system_actor'),
                'date' => $targetDate?->translatedFormat('M j, Y \a\t g:ia'),
            ])
        }}
    </p>

    @if ($blockingIssues !== [])
        <div
            class="border-danger-300 bg-danger-50 dark:border-danger-700/50 dark:bg-danger-900/20 rounded-lg border p-3"
        >
            <p class="text-danger-700 dark:text-danger-300 font-semibold">
                {{ __('capell-admin::event-sourcing.rollback_blocked') }}
            </p>
            <ul
                class="text-danger-700 dark:text-danger-300 mt-2 list-disc space-y-1 ps-5"
            >
                @foreach ($blockingIssues as $issue)
                    <li>{{ $issue->message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($warnings !== [])
        <div
            class="border-warning-300 bg-warning-50 dark:border-warning-700/50 dark:bg-warning-900/20 rounded-lg border p-3"
        >
            <ul
                class="text-warning-700 dark:text-warning-300 list-disc space-y-1 ps-5"
            >
                @foreach ($warnings as $issue)
                    <li>{{ $issue->message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($fieldDiffs === [])
        <p class="text-gray-600 dark:text-gray-300">
            {{ __('capell-admin::event-sourcing.rollback_no_changes') }}
        </p>
    @else
        <div class="divide-y divide-gray-100 dark:divide-gray-800">
            @foreach ($fieldDiffs as $field)
                <div class="py-3">
                    <p class="font-medium text-gray-700 dark:text-gray-200">
                        {{ $field->label }}
                    </p>
                    <div class="mt-1 grid gap-2 sm:grid-cols-2">
                        <div class="rounded bg-gray-50 p-2 dark:bg-white/5">
                            <span
                                class="block text-xs text-gray-500 uppercase dark:text-gray-300"
                            >
                                {{ __('capell-admin::event-sourcing.rollback_current') }}
                            </span>
                            <span class="text-gray-700 dark:text-gray-200">
                                {{ $field->beforeSummary }}
                            </span>
                        </div>
                        <div
                            class="bg-primary-50 dark:bg-primary-900/20 rounded p-2"
                        >
                            <span
                                class="block text-xs text-gray-500 uppercase dark:text-gray-300"
                            >
                                {{ __($direction->targetColumnKey()) }}
                            </span>
                            <span class="text-gray-700 dark:text-gray-200">
                                {{ $field->afterSummary }}
                            </span>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
