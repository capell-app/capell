@props([
    'count' => '',
    'description' => '',
    'icon' => '',
    'image' => '',
    'label' => '',
    'prefix' => '',
    'inline' => false,
    'size' => '',
])
@php
    use Capell\Admin\Contracts\Support\FlagIconRenderer;

    $iconName = is_string($icon) ? $icon : '';
    $isFlagIcon = str_starts_with($iconName, 'flag-4x3-') || str_starts_with($iconName, 'flag-1x1-');
@endphp

<div {{ $attributes->class('group flex w-full items-center gap-2') }}>
    @if ($image)
        <img
            class="h-10 w-10 overflow-hidden rounded-sm object-cover"
            src="{{ $image }}"
            role="img"
            loading="lazy"
        />
    @elseif ($isFlagIcon)
        {{ app(FlagIconRenderer::class)->render($iconName, attributes: ['class' => 'w-5 border border-gray-200 dark:border-none']) }}
    @elseif ($icon)
        <x-dynamic-component
            class="w-5 border border-gray-200 dark:border-none"
            :component="$icon"
        />
    @endif

    <div
        @class([
            'leading-none',
            'flex flex-col items-start justify-center' => ! $inline,
        ])
    >
        <span class="inline-flex gap-0.5">
            @if ($prefix)
                <div class="text-xs font-light">
                    {{ $prefix }}
                </div>
            @endif

            <div class="text-sm leading-normal font-normal">
                <span class="select-option-label">{{ $label }}</span>
                @if ($count)
                    <span class="text-xs">({{ $count }})</span>
                @endif
            </div>
        </span>
        @if ($description)
            <span
                class="select-selected-hidden inline-block text-xs font-light tracking-wide text-gray-500 group-hover:text-inherit"
            >
                {{ $description }}
            </span>
        @endif
    </div>
</div>
