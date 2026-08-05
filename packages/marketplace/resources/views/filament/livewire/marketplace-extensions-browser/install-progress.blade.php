@php
    /** @var array<int, array<string, mixed>> $progressRecords */
    $hasActiveInstalls = $this->hasActiveMarketplaceInstalls();
@endphp

{{-- Polling stops the moment nothing is still running, so a finished modal left
     open costs nothing. --}}
<div
    class="space-y-5"
    data-capell-marketplace-install-progress
    @if ($hasActiveInstalls) wire:poll.3s @endif
>
    <div class="space-y-1">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">
            {{ __('capell-marketplace::marketplace.progress.heading') }}
        </h3>
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('capell-marketplace::marketplace.progress.description') }}
        </p>
    </div>

    <div class="space-y-3">
        @foreach ($progressRecords as $progress)
            <div
                data-capell-marketplace-progress-card="{{ $progress['composer_name'] }}"
                data-capell-marketplace-progress-status="{{ $progress['status'] }}"
                class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="truncate text-sm font-semibold text-gray-950 dark:text-white">
                        {{ $progress['name'] }}
                    </p>

                    {{-- The compact per-card pill: what stage this operation is
                         at, without leaving the modal for the timeline. --}}
                    <span
                        data-capell-marketplace-progress-pill="{{ $progress['composer_name'] }}"
                        data-capell-marketplace-progress-stage="{{ $progress['stage'] }}"
                        @class([
                            'inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-medium ring-1',
                            'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-500/10 dark:text-blue-200' => $progress['active'],
                            'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-500/10 dark:text-green-200' => $progress['succeeded'],
                            'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-200' => ! $progress['active'] && ! $progress['succeeded'],
                        ])
                    >
                        @if ($progress['active'])
                            <x-filament::loading-indicator class="h-3 w-3" />
                        @endif

                        {{ $progress['stage_label'] }}
                    </span>
                </div>

                <p
                    data-capell-marketplace-progress-step="{{ $progress['progress_current'] }}/{{ $progress['progress_total'] }}"
                    class="mt-2 text-xs text-gray-500 dark:text-gray-400"
                >
                    {{ $progress['progress_current'] }}/{{ $progress['progress_total'] }}
                </p>

                @if ($progress['failure_reason'] !== null)
                    <p
                        data-capell-marketplace-progress-failure="{{ $progress['composer_name'] }}"
                        class="mt-2 text-sm text-red-700 dark:text-red-300"
                    >
                        {{ $progress['failure_reason'] }}
                    </p>
                @endif
            </div>
        @endforeach
    </div>

    <template x-teleport="#capell-marketplace-browser-modal-footer">
        <div
            class="relative z-50 flex w-full flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
        >
            <button
                type="button"
                wire:click="backToMarketplaceBrowseFromProgress"
                class="inline-flex items-center justify-center rounded-lg px-4 py-2.5 text-sm font-semibold text-gray-700 transition hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/10"
            >
                {{ __('capell-marketplace::marketplace.progress.back_to_browse') }}
            </button>

            {{-- The redirect the confirm step used to perform on its own. It is
                 still one click away, it is just no longer imposed. --}}
            <button
                type="button"
                wire:click="viewMarketplaceInstallOperations"
                data-capell-marketplace-view-operations
                class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-500 dark:bg-blue-500 dark:hover:bg-blue-400"
            >
                {{ __('capell-marketplace::marketplace.progress.view_operations') }}
            </button>
        </div>
    </template>
</div>
