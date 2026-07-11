@php
    use Capell\Backup\Models\ImportSession;

    /** @var ImportSession $record */
    $record = $getRecord();

    $stages = [
        ['label' => __('capell-admin::exchanger.created_at'), 'value' => $record->created_at, 'icon' => 'heroicon-o-inbox-arrow-down'],
        ['label' => __('capell-admin::exchanger.reviewed_at'), 'value' => $record->reviewed_at, 'icon' => 'heroicon-o-clipboard-document-check'],
        ['label' => __('capell-admin::exchanger.resolved_at'), 'value' => $record->resolved_at, 'icon' => 'heroicon-o-link'],
        ['label' => __('capell-admin::exchanger.validated_at'), 'value' => $record->validated_at, 'icon' => 'heroicon-o-shield-check'],
        ['label' => __('capell-admin::exchanger.executed_at'), 'value' => $record->executed_at, 'icon' => 'heroicon-o-play'],
        ['label' => __('capell-admin::exchanger.updated_at'), 'value' => $record->updated_at, 'icon' => 'heroicon-o-arrow-path'],
    ];
@endphp

<ol class="space-y-3">
    @foreach ($stages as $stage)
        @php
            $stageValue = $stage['value'];
            $hasStageValue = $stageValue !== null;
            $dotClasses = $hasStageValue
                ? 'bg-primary-500 text-white'
                : 'bg-gray-200 text-gray-500 dark:bg-gray-700 dark:text-gray-400';
        @endphp

        <li class="flex items-start gap-3">
            <div
                class="{{ $dotClasses }} mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full"
            >
                <x-filament::icon
                    :icon="$stage['icon']"
                    class="size-3.5"
                />
            </div>
            <div class="min-w-0 flex-1">
                <div
                    class="text-sm font-medium text-gray-900 dark:text-gray-100"
                >
                    {{ $stage['label'] }}
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    @if ($hasStageValue)
                        {{ $stageValue->toDayDateTimeString() }}
                    @else
                        {{ __('capell-admin::exchanger.timeline_pending') }}
                    @endif
                </div>
            </div>
        </li>
    @endforeach
</ol>
