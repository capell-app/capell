<x-filament-widgets::widget>
    @php
        $activeCount = $this->activeCount();
        $attentionCount = $this->attentionCount();
    @endphp

    @if ($activeCount > 0 || $attentionCount > 0)
        <x-filament::section>
            <div class="space-y-4">
                <div
                    class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div>
                        <h2
                            class="text-base font-semibold text-gray-950 dark:text-white"
                        >
                            {{ __('capell-marketplace::marketplace.operations.alert_heading') }}
                        </h2>
                        <p
                            class="mt-1 text-sm text-gray-600 dark:text-gray-300"
                        >
                            {{
                                trans_choice('capell-marketplace::marketplace.operations.alert_summary', $activeCount + $attentionCount, [
                                    'active' => $activeCount,
                                    'attention' => $attentionCount,
                                ])
                            }}
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        @if ($activeCount > 0)
                            <span
                                class="bg-info-50 text-info-700 ring-info-600/20 dark:bg-info-500/10 dark:text-info-300 rounded-md px-2.5 py-1 text-xs font-semibold ring-1"
                            >
                                {{ trans_choice('capell-marketplace::marketplace.operations.active_count', $activeCount, ['count' => $activeCount]) }}
                            </span>
                        @endif

                        @if ($attentionCount > 0)
                            <span
                                class="bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-500/10 dark:text-danger-300 rounded-md px-2.5 py-1 text-xs font-semibold ring-1"
                            >
                                {{ trans_choice('capell-marketplace::marketplace.operations.attention_count', $attentionCount, ['count' => $attentionCount]) }}
                            </span>
                        @endif
                    </div>
                </div>

                @if ($this->attentionOperations()->isNotEmpty())
                    <div class="space-y-2">
                        @foreach ($this->attentionOperations() as $operation)
                            <div
                                class="border-danger-200 bg-danger-50/70 dark:border-danger-500/30 dark:bg-danger-500/10 rounded-lg border p-3"
                                role="alert"
                            >
                                <div
                                    class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between"
                                >
                                    <div class="min-w-0">
                                        <div
                                            class="flex flex-wrap items-center gap-2"
                                        >
                                            <h3
                                                class="text-sm font-semibold text-gray-950 dark:text-white"
                                            >
                                                {{ $operation->extension_name }}
                                            </h3>
                                            @if ($operation->failure_stage)
                                                <span
                                                    class="rounded-md bg-white/80 px-2 py-1 text-xs font-medium text-gray-700 ring-1 ring-gray-600/20 dark:bg-white/10 dark:text-gray-200"
                                                >
                                                    {{ str($operation->failure_stage)->replace('_', ' ')->headline() }}
                                                </span>
                                            @endif

                                            @if ($operation->failure_type)
                                                <span
                                                    class="rounded-md bg-white/80 px-2 py-1 text-xs font-medium text-gray-700 ring-1 ring-gray-600/20 dark:bg-white/10 dark:text-gray-200"
                                                >
                                                    {{ str($operation->failure_type)->replace('_', ' ')->headline() }}
                                                </span>
                                            @endif
                                        </div>

                                        <p
                                            class="mt-1 text-sm break-words text-gray-700 dark:text-gray-200"
                                        >
                                            {{ str($operation->failure_reason ?? __('capell-marketplace::marketplace.operations.notification_unknown_reason'))->limit(240) }}
                                        </p>
                                    </div>

                                    <div class="flex shrink-0 flex-wrap gap-2">
                                        <a
                                            href="{{ $this->operationUrl($operation) }}"
                                            class="inline-flex items-center justify-center rounded-lg bg-white px-3 py-2 text-sm font-semibold text-gray-700 ring-1 ring-gray-600/20 transition hover:bg-gray-50 dark:bg-white/10 dark:text-gray-100 dark:hover:bg-white/15"
                                        >
                                            {{ __('capell-marketplace::marketplace.operations.view') }}
                                        </a>

                                        @if ($this->canRetry($operation))
                                            <button
                                                type="button"
                                                wire:click="retry({{ $operation->getKey() }})"
                                                class="inline-flex items-center justify-center rounded-lg bg-white px-3 py-2 text-sm font-semibold text-gray-700 ring-1 ring-gray-600/20 transition hover:bg-gray-50 dark:bg-white/10 dark:text-gray-100 dark:hover:bg-white/15"
                                            >
                                                {{ __('capell-marketplace::marketplace.operations.retry') }}
                                            </button>
                                        @endif

                                        <button
                                            type="button"
                                            wire:click="dismiss({{ $operation->getKey() }})"
                                            class="text-danger-700 ring-danger-600/20 hover:bg-danger-100 dark:text-danger-200 dark:hover:bg-danger-500/20 inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold ring-1 transition"
                                        >
                                            {{ __('capell-marketplace::marketplace.operations.dismiss') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
