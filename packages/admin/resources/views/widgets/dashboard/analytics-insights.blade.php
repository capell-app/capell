<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('capell-admin::dashboard.analytics_insights')"
    >
        @if ($this->data()->topPages === [] && $this->data()->topSearchTerms === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('capell-admin::dashboard.analytics_no_insights') }}
            </p>
        @else
            <div class="grid gap-6 lg:grid-cols-2">
                <div>
                    <h3 class="text-sm font-medium">
                        {{ __('capell-admin::dashboard.analytics_top_pages') }}
                    </h3>
                    <ol class="mt-3 space-y-2 text-sm">
                        @foreach ($this->data()->topPages as $page)
                            <li class="flex justify-between gap-4">
                                <span class="truncate">{{ $page['url'] }}</span>
                                <span
                                    class="font-medium"
                                    >{{ number_format($page['count']) }}</span
                                >
                            </li>
                        @endforeach
                    </ol>
                </div>
                @if ($this->data()->trendingPages !== [])
                    <div>
                        <h3 class="text-sm font-medium">
                            {{ __('capell-admin::dashboard.analytics_trending_pages') }}
                        </h3>
                        <ol class="mt-3 space-y-2 text-sm">
                            @foreach ($this->data()->trendingPages as $page)
                                <li class="flex justify-between gap-4">
                                    <span
                                        class="truncate"
                                        >{{ $page['url'] }}</span
                                    >
                                    <span class="font-medium text-success-600"
                                        >+{{ number_format($page['change']) }}</span
                                    >
                                </li>
                            @endforeach
                        </ol>
                    </div>
                @endif
                @if ($this->data()->topSearchTerms !== [])
                    <div>
                        <h3 class="text-sm font-medium">
                            {{ __('capell-admin::dashboard.analytics_top_search_terms') }}
                        </h3>
                        <ol class="mt-3 space-y-2 text-sm">
                            @foreach ($this->data()->topSearchTerms as $term)
                                <li class="flex justify-between gap-4">
                                    <span
                                        class="truncate"
                                        >{{ $term['term'] }}</span
                                    >
                                    <span
                                        class="font-medium"
                                        >{{ number_format($term['count']) }}</span
                                    >
                                </li>
                            @endforeach
                        </ol>
                    </div>
                @endif
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
