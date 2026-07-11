@php
    $report = $this->getReport();
    $siteOptions = $this->siteOptions();
    $selectedSiteId = $this->normalisedSelectedSiteId();

    $badgeColor = function (string $status): string {
        return match ($status) {
            'green', 'HIT' => 'success',
            'red', 'BYPASS' => 'danger',
            'amber', 'MISS' => 'warning',
            default => 'gray',
        };
    };
@endphp

<x-filament-panels::page>
    <div class="space-y-6">
        <div class="flex justify-end">
            <div class="w-full max-w-xs">
                <label
                    for="site-health-site-selector"
                    class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300"
                >
                    {{ __('capell-admin::generic.site_health_site') }}
                </label>

                <x-filament::input.wrapper>
                    <x-filament::input.select
                        id="site-health-site-selector"
                        wire:model.live="selectedSiteId"
                    >
                        @forelse ($siteOptions as $siteId => $siteName)
                            <option value="{{ $siteId }}">
                                {{ $siteName }}
                            </option>
                        @empty
                            <option value="">
                                {{ __('capell-admin::generic.no_sites') }}
                            </option>
                        @endforelse
                    </x-filament::input.select>
                </x-filament::input.wrapper>

                <p
                    class="sr-only"
                    role="status"
                    aria-live="polite"
                    wire:loading
                    wire:target="selectedSiteId"
                >
                    {{ __('capell-admin::generic.site_health_updating') }}
                </p>

                @if ($siteOptions !== [])
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('capell-admin::generic.site_health_site_helper') }}
                    </p>
                @else
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('capell-admin::generic.site_health_no_sites_description') }}
                    </p>
                @endif
            </div>
        </div>

        <div
            class="grid gap-6 lg:grid-cols-2"
            wire:loading.attr="aria-busy"
            wire:target="selectedSiteId"
        >
            @foreach ($report->sections() as $section)
                <x-filament::section :heading="$section->label">
                    <ul class="space-y-3">
                        @foreach ($section->checks as $check)
                            <li
                                class="rounded-lg border border-gray-200 p-3 dark:border-gray-700"
                            >
                                <div
                                    class="flex min-w-0 items-start justify-between gap-3"
                                >
                                    <div class="min-w-0">
                                        <h3
                                            class="font-medium text-gray-900 dark:text-gray-100"
                                        >
                                            {{ $check->label }}
                                        </h3>
                                        <div
                                            class="mt-1 text-sm [overflow-wrap:anywhere] break-words text-gray-600 dark:text-gray-400"
                                        >
                                            {{ $check->detail }}
                                        </div>
                                    </div>
                                    <x-filament::badge
                                        :color="$badgeColor($check->status)"
                                        class="shrink-0"
                                    >
                                        <span class="sr-only">
                                            {{ __('capell-admin::generic.status') }}:
                                        </span>
                                        {{ strtoupper($check->status) }}
                                    </x-filament::badge>
                                </div>

                                @if ($check->path || $check->generatedAt || $check->remediation)
                                    <div
                                        class="mt-3 space-y-1 text-xs text-gray-500 dark:text-gray-400"
                                    >
                                        @if ($check->path)
                                            <div
                                                class="overflow-x-auto font-mono break-all"
                                            >
                                                {{ $check->path }}
                                            </div>
                                        @endif

                                        @if ($check->generatedAt)
                                            <div>
                                                {{ __('capell-admin::generic.generated') }}:
                                                {{ $check->generatedAt }}
                                            </div>
                                        @endif

                                        @if ($check->remediation)
                                            <div>
                                                {{ $check->remediation }}
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </x-filament::section>
            @endforeach
        </div>

        @foreach ($this->siteHealthWidgets() as $widget)
            @livewire($widget->component(),
                $this->siteHealthWidgetParameters($widget),
                key($widget->key() . '-' . ($selectedSiteId ?? 'none')))
        @endforeach
    </div>
</x-filament-panels::page>
