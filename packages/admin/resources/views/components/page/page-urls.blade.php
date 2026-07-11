@php
    use Capell\Admin\Actions\Pages\BuildPageUrlsViewDataAction;
    use Capell\Admin\Support\PageUrlPresenter;
    use Capell\Core\Models\Page;

    /** @var Page $record */
    $record = $getRecord();
    $viewData = BuildPageUrlsViewDataAction::run($record);
    $pageUrls = $viewData['pageUrls'];
    $flagIconRenderer = $viewData['flagIconRenderer'];
@endphp

<x-filament::section
    compact
    collapsible
>
    <x-slot name="heading">
        {{ __('capell-admin::generic.page_urls') }}
    </x-slot>
    <div class="flex flex-col divide-y divide-gray-200 dark:divide-gray-700">
        @foreach ($pageUrls as $pageUrl)
            @php($fullUrl = PageUrlPresenter::fullUrl($pageUrl))
            <div class="py-1">
                @if ($fullUrl !== null)
                    <x-filament::link
                        :href="$fullUrl"
                        target="_blank"
                    >
                        @if ($pageUrl->language && $pageUrl->language->flag)
                            {!!
                                $flagIconRenderer->render(
                                    $pageUrl->language->flag,
                                    $pageUrl->language->name,
                                    attributes: ['class' => 'mr-2 inline-block h-4 w-5'],
                                )
                            !!}
                        @endif

                        {{ $fullUrl }}
                    </x-filament::link>
                @else
                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        @if ($pageUrl->language && $pageUrl->language->flag)
                            {!!
                                $flagIconRenderer->render(
                                    $pageUrl->language->flag,
                                    $pageUrl->language->name,
                                    attributes: ['class' => 'mr-2 inline-block h-4 w-5'],
                                )
                            !!}
                        @endif

                        {{ $pageUrl->url }}
                    </span>
                @endif
            </div>
        @endforeach
    </div>
</x-filament::section>
