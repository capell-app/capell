@props([
    'bodyClass' => null,
    'language',
    'layout',
    'pageRecord',
    'site',
    'theme',
])

@includeIsolated('capell::public.body', [
    'renderData' => app(\Capell\Frontend\Data\PublicBodyData::class, [
        'layout' => $layout,
        'theme' => $theme,
        'bodyClass' => $bodyClass,
    ]),
    'slot' => $slot,
])
