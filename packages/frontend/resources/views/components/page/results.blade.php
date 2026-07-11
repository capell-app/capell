@php
    use Capell\Core\Actions\ResolveRenderableComponentAction;
    use Capell\Core\Enums\AssetComponentEnum;
    use Capell\Core\Enums\RenderableTypeEnum;
    use Capell\Frontend\Actions\RenderHtmlContentAction;
    use Capell\Frontend\Enums\RenderHookLocation;
    use Capell\Frontend\Facades\Frontend;
    use Capell\Frontend\Support\Render\RenderHookRegistry;
    use Capell\Frontend\Support\View\PublicModelMeta;
    use Illuminate\Contracts\Pagination\LengthAwarePaginator;

    $page = Frontend::page();
    $language = Frontend::language();
    $site = Frontend::site();
@endphp

@props([
    'component' => null,
    'componentItem' => AssetComponentEnum::Card->value,
    'noResultsText' => __('capell-frontend::generic.no_results'),
    'results',
    'wireLinks' => true,
    'withParent' => false,
    'wireChildrenCount' => false,
])
@php
    $componentItem = ResolveRenderableComponentAction::run(RenderableTypeEnum::Asset, $componentItem);
    $resultCount = $results
        ? (method_exists($results, 'total') ? $results->total() : (method_exists($results, 'count') ? $results->count() : 0))
        : 0;
    $currentPageIsEmpty = ! $results || $results->isEmpty();
@endphp

<div
    {{ $attributes->merge(['class' => 'capell-component capell-page-results results @container']) }}
>
    <p
        class="sr-only"
        role="status"
        aria-live="polite"
    >
        {{ $resultCount }} {{ __('capell-frontend::messages.results_found') }}
    </p>

    @if ($currentPageIsEmpty)
        <x-capell::no-results>
            {{ RenderHtmlContentAction::run((string) $noResultsText) }}
        </x-capell::no-results>
    @else
        <div
            class="grid w-full max-w-full min-w-0 gap-5 overflow-hidden @3xl:grid-cols-2"
            role="list"
        >
            @foreach ($results as $item)
                @php
                    $author = method_exists($item, 'relationLoaded') && $item->relationLoaded('creator') ? $item->creator : null;
                    $image = method_exists($item, 'relationLoaded') && $item->relationLoaded('image') ? $item->image : null;
                    $parent = $withParent && method_exists($item, 'relationLoaded') && $item->relationLoaded('parent') ? $item->parent : null;
                    $pageUrl = method_exists($item, 'relationLoaded') && $item->relationLoaded('pageUrl') ? $item->pageUrl : null;
                    $translation = method_exists($item, 'relationLoaded') && $item->relationLoaded('translation') ? $item->translation : null;
                    $squareImage = (bool) PublicModelMeta::get($item, 'square_image', false);
                    $publishDatePosition = PublicModelMeta::get($translation, 'publish_date_position', 'top');
                @endphp

                {!! app(RenderHookRegistry::class)->renderAll(RenderHookLocation::BeforeResult, $item) !!}
                <x-dynamic-component
                    :component="$componentItem"
                    :$loop
                    :asset="$item"
                    :author="$author"
                    :count="$wireChildrenCount ? $item->children_count : null"
                    :image="$image"
                    :link-text="__('capell-frontend::generic.read_more')"
                    :parent="$parent"
                    :publish-date="$item->getPublishDate()"
                    :summary="$translation?->summary"
                    :title="$translation?->title"
                    :url="$pageUrl?->full_url"
                    :square-image="$squareImage"
                    :with-summary="true"
                    :with-author="true"
                    :publish-date-position="$publishDatePosition"
                    role="listitem"
                />
                {!! app(RenderHookRegistry::class)->renderAll(RenderHookLocation::AfterResult, $item) !!}
            @endforeach
        </div>
    @endif

    @if ($results instanceof LengthAwarePaginator)
        <x-capell::pagination
            :$results
            :wire-links="$wireLinks"
            scroll-to-element="#main"
            class="my-6 mt-10 flex flex-col items-center justify-center gap-6 lg:mt-16 dark:text-white"
        />
    @endif
</div>
