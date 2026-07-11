@php
    use Capell\Core\Enums\ContentStructure;
    use Capell\Frontend\Actions\GetLayoutContainerWidthAction;
    use Capell\Frontend\Data\MainContentRenderHookData;
    use Capell\Frontend\Enums\RenderHookLocation;
    use Capell\Frontend\Support\Render\RenderHookRegistry;

    $containerWidth = GetLayoutContainerWidthAction::run();
    $translation = method_exists($page, 'relationLoaded') && $page->relationLoaded('translation') ? $page->translation : null;
    $type = method_exists($page, 'relationLoaded') && $page->relationLoaded('blueprint') ? $page->blueprint : null;
    $themeData = is_array($theme) ? $theme : [];
@endphp

@props([
    'containerClass' => null,
    'layout',
    'mainClass' => null,
    'mainContainerClass' => null,
    'page',
    'pageSlot' => null,
    'theme' => [],
])
@php
    $mainContentHookData = new MainContentRenderHookData(
        layout: $layout,
        page: $page,
        pageSlot: $pageSlot,
        theme: $themeData,
        containerClass: $containerClass,
        mainClass: $mainClass,
        mainContainerClass: $mainContainerClass,
    );

    $mainContentHookOutput = app(RenderHookRegistry::class)->renderAll(
        RenderHookLocation::MainContent,
        $mainContentHookData,
        scenario: 'frontend-main-layout',
        target: 'capell::layout.main',
    );
@endphp

<main
    id="main"
    @class([
        'relative z-0 flex min-h-full flex-1 flex-col overflow-x-hidden lg:!min-h-0',
        'capell-component capell-layout-main',
        $themeData['meta']['main_class'] ?? 'py-6 lg:py-10',
        $containerWidth->getContainerClass(),
        $mainClass ?? '',
    ])
>
    @if ($mainContentHookOutput !== '')
        {!! $mainContentHookOutput !!}
    @else
        <x-capell::content
            :content="$translation?->content ?? ''"
            :content-type="$type?->content_structure ?? ContentStructure::Html"
            :title="$translation?->title ?? ''"
            heading-tag="h1"
        />
    @endif

    @if ($pageSlot && ! $mainContentHookData->slotRendered)
        {{ $pageSlot }}
    @endif
</main>
