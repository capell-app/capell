<x-filament-panels::page>
    @php
        $theme = $this->theme();
        $sites = $this->sites();
    @endphp

    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">
                {{ __('capell-marketplace::marketplace.theme_extension.apply_heading') }}
            </x-slot>

            <x-slot name="description">
                {{ __('capell-marketplace::marketplace.theme_extension.apply_description') }}
            </x-slot>

            <div class="grid gap-4 lg:grid-cols-3">
                <label
                    class="flex cursor-pointer gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900"
                >
                    <input
                        type="radio"
                        value="all"
                        wire:model.live="scope"
                        class="mt-1"
                    />
                    <span>
                        <span
                            class="block text-sm font-medium text-gray-950 dark:text-white"
                        >
                            {{ __('capell-marketplace::marketplace.theme_extension.scope_all_label') }}
                        </span>
                        <span
                            class="mt-1 block text-sm text-gray-600 dark:text-gray-300"
                        >
                            {{ trans_choice('capell-marketplace::marketplace.theme_extension.scope_all_description', $sites->count(), ['count' => $sites->count()]) }}
                        </span>
                    </span>
                </label>

                <label
                    class="flex cursor-pointer gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm lg:col-span-2 dark:border-white/10 dark:bg-gray-900"
                >
                    <input
                        type="radio"
                        value="site"
                        wire:model.live="scope"
                        class="mt-1"
                    />
                    <span class="w-full">
                        <span
                            class="block text-sm font-medium text-gray-950 dark:text-white"
                        >
                            {{ __('capell-marketplace::marketplace.theme_extension.scope_site_label') }}
                        </span>
                        <span
                            class="mt-1 block text-sm text-gray-600 dark:text-gray-300"
                        >
                            {{ __('capell-marketplace::marketplace.theme_extension.scope_site_description') }}
                        </span>

                        <select
                            wire:model.live="siteId"
                            @disabled($this->scope !== 'site')
                            class="mt-3 w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm disabled:opacity-60 dark:border-white/10 dark:bg-gray-950"
                        >
                            <option value="">
                                {{ __('capell-marketplace::marketplace.theme_extension.site_placeholder') }}
                            </option>
                            @foreach ($sites as $site)
                                <option value="{{ $site->getKey() }}">
                                    {{ $site->name }} -
                                    {{ $site->theme?->name ?? __('capell-marketplace::marketplace.theme_extension.no_theme') }}
                                </option>
                            @endforeach
                        </select>
                    </span>
                </label>
            </div>

            <div class="mt-5 flex flex-wrap gap-3">
                <x-filament::button
                    color="gray"
                    icon="heroicon-o-eye"
                    wire:click="previewTheme"
                >
                    {{ __('capell-marketplace::marketplace.theme_extension.preview_button', ['theme' => $this->themeName()]) }}
                </x-filament::button>

                <x-filament::button
                    icon="heroicon-o-check-circle"
                    wire:click="applyTheme"
                >
                    {{ __('capell-marketplace::marketplace.theme_extension.apply_button', ['theme' => $this->themeName()]) }}
                </x-filament::button>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">
                {{ __('capell-marketplace::marketplace.theme_extension.current_usage_heading') }}
            </x-slot>

            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($sites as $site)
                    <div
                        class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3
                                    class="text-sm font-medium text-gray-950 dark:text-white"
                                >
                                    {{ $site->name }}
                                </h3>
                                <p
                                    class="mt-1 text-sm text-gray-600 dark:text-gray-300"
                                >
                                    {{ $site->theme?->name ?? __('capell-marketplace::marketplace.theme_extension.no_theme') }}
                                </p>
                            </div>

                            @if ($site->theme?->key === $this->themeKey)
                                <span
                                    class="rounded bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-100 dark:ring-emerald-400/40"
                                >
                                    {{ __('capell-marketplace::marketplace.theme_extension.active_badge') }}
                                </span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($theme === null)
                <p
                    class="mt-4 rounded-lg bg-yellow-50 p-3 text-sm text-yellow-900 ring-1 ring-yellow-200 dark:bg-yellow-900/30 dark:text-yellow-100 dark:ring-yellow-400/40"
                >
                    {{ __('capell-marketplace::marketplace.theme_extension.theme_will_be_created') }}
                </p>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
