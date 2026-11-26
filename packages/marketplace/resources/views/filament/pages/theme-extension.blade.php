<x-filament-panels::page>
    @php
        $theme = $this->theme();
        $sites = $this->sites();
        $themeName = $this->themeName();
        $setsDefault = $this->appliesDefaultTheme();
        $usingCount = $sites->filter(fn ($site): bool => $site->theme?->key === $this->themeKey)->count();
        $orderedSites = $sites->sortByDesc(fn ($site): bool => $site->theme?->key === $this->themeKey)->values();
        $panel = 'rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10';
        $noSitesToChange = $this->hasNoSitesToChange();
        $choiceFrame = 'flex gap-3 rounded-lg p-4 ring-1 ring-gray-950/10 transition has-[:checked]:bg-primary-50 has-[:checked]:ring-2 has-[:checked]:ring-primary-600 dark:ring-white/10 dark:has-[:checked]:bg-primary-500/10 dark:has-[:checked]:ring-primary-500';
        $choice = 'cursor-pointer ' . $choiceFrame;
        $radio = 'mt-0.5 size-4 shrink-0 accent-primary-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 dark:accent-primary-500';
    @endphp

    <div
        class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start"
        data-capell-theme-extension
    >
        <section
            class="{{ $panel }}"
            aria-labelledby="theme-extension-apply-heading"
        >
            <header
                class="border-b border-gray-950/5 px-5 py-4 dark:border-white/10"
            >
                <h2
                    id="theme-extension-apply-heading"
                    class="text-base font-semibold text-gray-950 dark:text-white"
                >
                    {{ __('capell-marketplace::marketplace.theme_extension.apply_heading') }}
                </h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    {{ __('capell-marketplace::marketplace.theme_extension.apply_description', ['theme' => $themeName]) }}
                </p>
            </header>

            <fieldset class="space-y-3 p-5">
                <legend class="sr-only">
                    {{ __('capell-marketplace::marketplace.theme_extension.scope_legend', ['theme' => $themeName]) }}
                </legend>

                <label
                    class="{{ $choice }}"
                    data-capell-theme-scope="all"
                >
                    <input
                        type="radio"
                        value="all"
                        wire:model.live="scope"
                        class="{{ $radio }}"
                    />
                    <span class="min-w-0">
                        <span
                            class="block text-sm font-medium text-gray-950 dark:text-white"
                        >
                            {{ __('capell-marketplace::marketplace.theme_extension.scope_all_label') }}
                        </span>
                        <span
                            class="mt-1 block text-sm text-gray-600 dark:text-gray-300"
                        >
                            @if ($setsDefault)
                                {{ trans_choice('capell-marketplace::marketplace.theme_extension.scope_all_description', $sites->count(), ['count' => $sites->count()]) }}
                            @else
                                {{ trans_choice('capell-marketplace::marketplace.theme_extension.scope_all_description_scoped', $sites->count(), ['count' => $sites->count()]) }}
                            @endif
                        </span>
                    </span>
                </label>

                <div
                    class="{{ $choiceFrame }} flex-col"
                    data-capell-theme-scope="site"
                >
                    <label class="flex cursor-pointer gap-3">
                        <input
                            type="radio"
                            value="site"
                            wire:model.live="scope"
                            class="{{ $radio }}"
                        />
                        <span class="min-w-0">
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
                        </span>
                    </label>

                    @if ($this->scope === 'site')
                        <div class="ps-7">
                            <label
                                for="theme-extension-site"
                                class="sr-only"
                            >
                                {{ __('capell-marketplace::marketplace.theme_extension.site_placeholder') }}
                            </label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select
                                    id="theme-extension-site"
                                    wire:model.live="siteId"
                                >
                                    <option value="">
                                        {{ __('capell-marketplace::marketplace.theme_extension.site_placeholder') }}
                                    </option>
                                    @foreach ($sites as $site)
                                        <option value="{{ $site->getKey() }}">
                                            {{ __('capell-marketplace::marketplace.theme_extension.site_option', [
                                                'site' => $site->name,
                                                'theme' => $site->theme?->name ?? __('capell-marketplace::marketplace.theme_extension.no_theme'),
                                            ]) }}
                                        </option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>
                    @endif
                </div>

                @if ($theme === null)
                    <p
                        class="flex gap-2 rounded-lg bg-info-50 p-3 text-sm text-info-900 ring-1 ring-info-600/20 dark:bg-info-500/10 dark:text-info-100 dark:ring-info-400/20"
                        data-capell-theme-will-be-created
                    >
                        <x-filament::icon
                            icon="heroicon-o-information-circle"
                            class="mt-0.5 size-4 shrink-0"
                        />
                        <span
                            >{{ __('capell-marketplace::marketplace.theme_extension.theme_will_be_created') }}</span
                        >
                    </p>
                @endif
            </fieldset>

            <footer
                class="flex flex-col-reverse gap-3 rounded-b-xl border-t border-gray-950/5 bg-gray-50 px-5 py-4 sm:flex-row sm:justify-end dark:border-white/10 dark:bg-white/5"
            >
                <x-filament::button
                    color="gray"
                    icon="heroicon-o-eye"
                    wire:click="previewTheme"
                >
                    {{ __('capell-marketplace::marketplace.theme_extension.preview_button', ['theme' => $themeName]) }}
                </x-filament::button>

                @if ($noSitesToChange)
                    <p
                        class="text-sm text-gray-600 sm:me-auto sm:self-center dark:text-gray-300"
                        data-capell-theme-apply-disabled
                    >
                        {{ __('capell-marketplace::marketplace.theme_extension.apply_disabled_no_sites') }}
                    </p>
                @endif

                <x-filament::button
                    icon="heroicon-o-check-circle"
                    wire:click="applyTheme"
                    :disabled="$noSitesToChange"
                >
                    {{ __('capell-marketplace::marketplace.theme_extension.apply_button', ['theme' => $themeName]) }}
                </x-filament::button>
            </footer>
        </section>

        <section
            class="{{ $panel }} lg:sticky lg:top-20"
            aria-labelledby="theme-extension-usage-heading"
        >
            <header
                class="border-b border-gray-950/5 px-5 py-4 dark:border-white/10"
            >
                <h2
                    id="theme-extension-usage-heading"
                    class="text-base font-semibold text-gray-950 dark:text-white"
                >
                    {{ __('capell-marketplace::marketplace.theme_extension.current_usage_heading') }}
                </h2>
                @if ($sites->isNotEmpty())
                    <p
                        class="mt-1 text-sm text-gray-600 dark:text-gray-300"
                        data-capell-theme-usage-summary
                    >
                        {{ trans_choice('capell-marketplace::marketplace.theme_extension.usage_summary', $usingCount, [
                            'count' => $usingCount,
                            'total' => $sites->count(),
                            'theme' => $themeName,
                        ]) }}
                    </p>
                @endif
            </header>

            @if ($sites->isEmpty())
                <div
                    class="flex flex-col items-center gap-3 px-5 py-10 text-center"
                >
                    <span
                        class="flex size-12 items-center justify-center rounded-full bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400"
                    >
                        <x-filament::icon
                            icon="heroicon-o-globe-alt"
                            class="size-6"
                        />
                    </span>
                    <p class="max-w-xs text-sm text-gray-600 dark:text-gray-300">
                        {{ __('capell-marketplace::marketplace.theme_extension.no_sites') }}
                    </p>
                </div>
            @else
                <ul class="divide-y divide-gray-950/5 dark:divide-white/10">
                    @foreach ($orderedSites as $site)
                        @php($active = $site->theme?->key === $this->themeKey)
                        <li
                            class="flex items-center justify-between gap-3 px-5 py-3"
                            data-capell-theme-site="{{ $site->getKey() }}"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-gray-950 dark:text-white">
                                    {{ $site->name }}
                                </p>
                                <p class="truncate text-sm text-gray-600 dark:text-gray-300">
                                    {{ $site->theme?->name ?? __('capell-marketplace::marketplace.theme_extension.no_theme') }}
                                </p>
                            </div>

                            @if ($active)
                                <x-filament::badge
                                    color="success"
                                    icon="heroicon-m-check"
                                    data-capell-theme-site-active
                                >
                                    {{ __('capell-marketplace::marketplace.theme_extension.active_badge') }}
                                </x-filament::badge>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</x-filament-panels::page>
