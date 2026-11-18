@props([
    'containerClass' => null,
    'layout',
    'mainClass' => null,
    'mainContainerClass' => null,
    'page',
    'pageSlot' => null,
    'theme' => [],
])

@includeIsolated('capell::public.layout-main', [
    'renderData' => app(\Capell\Frontend\Data\PublicMainLayoutData::class, [
        'layout' => $layout,
        'page' => $page,
        'pageSlot' => $pageSlot,
        'theme' => $theme,
        'containerClass' => $containerClass,
        'mainClass' => $mainClass,
        'mainContainerClass' => $mainContainerClass,
    ]),
])
