@php
    $findings = $this->findings();
@endphp
<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('capell-admin::dashboard.attention_heading')"
        :description="__('capell-admin::dashboard.attention_description')"
    >
        @if ($findings === [])
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('capell-admin::dashboard.attention_clear') }}</p>
        @else
            <ul class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($findings as $finding)
                    <li
                        class="flex flex-col gap-2 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $finding->recordLabel }}</p>
                            <p class="text-sm text-gray-600 dark:text-gray-400">{{ $finding->description }}</p>
                        </div>
                        @if ($finding->url !== null)
                            <x-filament::link
                                :href="$finding->url"
                                class="shrink-0"
                                >{{ $finding->actionLabel }}</x-filament::link
                            >
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
        <div class="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-sm">
            @if (\Capell\Admin\Filament\Pages\Reports\PublishingReadinessReport::canAccess() && \Capell\Admin\Filament\Pages\Reports\PublishingReadinessReport::shouldRegisterNavigation())
                <x-filament::link
                    :href="\Capell\Admin\Filament\Pages\Reports\PublishingReadinessReport::getUrl()"
                    >{{ __('capell-admin::dashboard.review_publishing') }}</x-filament::link
                >
            @endif
            @if (\Capell\Admin\Filament\Pages\SiteHealthPage::canAccess())
                <x-filament::link
                    :href="\Capell\Admin\Filament\Pages\SiteHealthPage::getUrl()"
                    >{{ __('capell-admin::navigation.site_health') }}</x-filament::link
                >
            @endif
            @if (\Capell\Admin\Filament\Pages\ExtensionsPage::canAccess())
                <x-filament::link
                    :href="\Capell\Admin\Filament\Pages\ExtensionsPage::getUrl()"
                    >{{ __('capell-admin::dashboard.review_extensions') }}</x-filament::link
                >
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
