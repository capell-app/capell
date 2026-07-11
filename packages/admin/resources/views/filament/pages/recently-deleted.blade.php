<x-filament-panels::page>
    <div class="space-y-8">
        @foreach ($groups as $group)
            <div>
                <h2
                    class="flex items-center gap-2 text-base font-semibold text-gray-950 dark:text-white"
                >
                    <x-filament::icon
                        :icon="$group['icon']"
                        class="h-5 w-5 text-gray-400"
                    />
                    {{ $group['label'] }}
                    <span class="text-sm font-normal text-gray-500">
                        ({{ $group['items']->count() }})
                    </span>
                </h2>

                @if ($group['items']->isEmpty())
                    <p class="mt-2 text-sm text-gray-500">
                        {{ __('capell-admin::generic.recently_deleted_none') }}
                    </p>
                @else
                    <ul
                        class="mt-3 divide-y divide-gray-100 rounded-lg border border-gray-200 bg-white dark:divide-white/5 dark:border-white/10 dark:bg-gray-900"
                    >
                        @foreach ($group['items'] as $item)
                            <li
                                class="flex items-center justify-between gap-4 px-4 py-3"
                            >
                                <div class="min-w-0 flex-1">
                                    <p
                                        class="truncate text-sm font-medium text-gray-900 dark:text-white"
                                    >
                                        {{ $item->name ?? $item->file_name ?? '#' . $item->id }}
                                    </p>
                                    <p class="truncate text-xs text-gray-500">
                                        {{ __('capell-admin::generic.deleted') }}
                                        {{ $item->deleted_at?->diffForHumans() }}
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <button
                                        type="button"
                                        wire:click="restoreRecord('{{ $group['resource'] }}', {{ $item->id }})"
                                        class="bg-primary-50 text-primary-700 hover:bg-primary-100 dark:bg-primary-500/10 dark:text-primary-300 dark:hover:bg-primary-500/20 inline-flex items-center gap-1 rounded-md px-2.5 py-1.5 text-xs font-medium"
                                    >
                                        <x-filament::icon
                                            icon="heroicon-m-arrow-uturn-left"
                                            class="h-3.5 w-3.5"
                                        />
                                        {{ __('capell-admin::button.restore') }}
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="forceDeleteRecord('{{ $group['resource'] }}', {{ $item->id }})"
                                        wire:confirm="{{ __('capell-admin::generic.recently_deleted_force_confirm') }}"
                                        class="bg-danger-50 text-danger-700 hover:bg-danger-100 dark:bg-danger-500/10 dark:text-danger-300 dark:hover:bg-danger-500/20 inline-flex items-center gap-1 rounded-md px-2.5 py-1.5 text-xs font-medium"
                                    >
                                        <x-filament::icon
                                            icon="heroicon-m-trash"
                                            class="h-3.5 w-3.5"
                                        />
                                        {{ __('capell-admin::button.delete_permanently') }}
                                    </button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
