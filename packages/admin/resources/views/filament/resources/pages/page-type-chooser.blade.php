<div class="space-y-3">
    @forelse ($pageTypes as $pageType)
        <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        @if (data_get($pageType->admin, 'icon'))
                            <x-filament::icon
                                :icon="data_get($pageType->admin, 'icon')"
                                class="h-5 w-5 text-gray-500 dark:text-gray-400"
                            />
                        @endif

                        <h3 class="font-semibold text-gray-950 dark:text-white">
                            {{ $pageType->name }}
                        </h3>

                        @if ($pageType->default)
                            <x-filament::badge color="primary">
                                {{ __('capell-admin::generic.page_type_chooser_default_badge') }}
                            </x-filament::badge>
                        @endif
                    </div>

                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {{ data_get($pageType->admin, 'notes') ?: __('capell-admin::generic.page_type_chooser_no_guidance') }}
                    </p>

                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        {{ trans_choice('capell-admin::generic.page_type_chooser_usage', (int) $pageType->pages_count, ['count' => (int) $pageType->pages_count]) }}
                    </p>
                </div>

                <x-filament::button
                    :href="$pageType->getAttribute('create_url')"
                    tag="a"
                    size="sm"
                >
                    {{ __('capell-admin::button.use_page_type') }}
                </x-filament::button>
            </div>
        </div>
    @empty
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('capell-admin::generic.no_blueprints_description') }}
        </p>
    @endforelse
</div>
