@php
    use Capell\Admin\Actions\Layouts\BuildLayoutImpactPreviewAction;
    use Capell\Core\Models\Layout;

    /** @var Layout|null $record */
    $impact = $record instanceof Layout ? BuildLayoutImpactPreviewAction::run($record) : null;
@endphp

@if ($impact === null)
    <p class="text-sm text-gray-500 dark:text-gray-400">
        {{ __('capell-admin::generic.layout_impact_preview_unavailable') }}
    </p>
@elseif ($impact->pageCount === 0)
    <p class="text-sm text-gray-500 dark:text-gray-400">
        {{ __('capell-admin::generic.layout_impact_preview_none') }}
    </p>
@else
    <div class="space-y-4">
        <div class="grid gap-3 sm:grid-cols-3">
            <div
                class="rounded-lg border border-gray-200 px-4 py-3 dark:border-white/10"
            >
                <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">
                    {{ __('capell-admin::generic.layout_impact_preview_pages') }}
                </p>
                <p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $impact->pageCount }}</p>
            </div>
            <div
                class="rounded-lg border border-gray-200 px-4 py-3 dark:border-white/10"
            >
                <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">
                    {{ __('capell-admin::generic.layout_impact_preview_sites') }}
                </p>
                <p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $impact->siteCount }}</p>
            </div>
            <div
                class="rounded-lg border border-gray-200 px-4 py-3 dark:border-white/10"
            >
                <p class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">
                    {{ __('capell-admin::generic.layout_impact_preview_locales') }}
                </p>
                <p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $impact->localeCount }}</p>
            </div>
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ __('capell-admin::generic.layout_impact_preview_exact') }}
        </p>

        <div
            class="divide-y divide-gray-200 overflow-hidden rounded-lg border border-gray-200 dark:divide-white/10 dark:border-white/10"
        >
            @foreach ($impact->pages as $page)
                <div class="space-y-2 px-4 py-3">
                    <div
                        class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1"
                    >
                        <p class="font-medium text-gray-950 dark:text-white">{{ $page->name }}</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $page->type }} · {{ $page->site }}</p>
                    </div>

                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ __('capell-admin::generic.layout_impact_preview_locales_for_page', ['locales' => implode(', ', $page->locales)]) }}
                    </p>

                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                        @foreach ($page->urls as $url)
                            <a
                                href="{{ $url->url }}"
                                target="_blank"
                                rel="noreferrer"
                                class="text-primary-600 hover:text-primary-500 dark:text-primary-400"
                            >
                                {{ $url->locale }}: {{ $url->url }}
                            </a>
                        @endforeach

                        @if ($page->urls === [])
                            <span class="text-gray-500 dark:text-gray-400">
                                {{ __('capell-admin::generic.layout_impact_preview_no_public_urls') }}
                            </span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <div class="space-y-1 text-sm text-gray-600 dark:text-gray-300">
            <p>{{ __('capell-admin::generic.layout_impact_preview_cache') }}</p>
            <p>{{ __('capell-admin::generic.layout_impact_preview_graph') }}</p>
            <p>{{ __('capell-admin::generic.layout_impact_preview_reversibility') }}</p>
        </div>
    </div>
@endif
