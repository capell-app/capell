@php
    use Capell\Frontend\Support\View\PublicModelMeta;
@endphp

@props([
    'bodyClass' => null,
    'language',
    'layout',
    'pageRecord',
    'site',
    'theme',
])

<body
    @class([
        'layout-' . $layout->key,
        PublicModelMeta::get($layout, 'body_class'),
        PublicModelMeta::get($theme, 'body_class'),
        $bodyClass,
    ])
>
    {{ $slot }}
</body>
