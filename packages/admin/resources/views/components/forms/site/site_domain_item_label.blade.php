@php
    use Capell\Admin\Contracts\Support\FlagIconRenderer;

    $flagIconRenderer = app(FlagIconRenderer::class);
@endphp

<div class="flex items-center gap-3">
    @if ($language->flag)
        {!!
            $flagIconRenderer->render(
                $language->flag,
                $language->name,
                attributes: ['x-tooltip.raw' => $language->name],
            )
        !!}
    @else
        <span
            class="hover:text-primary-600 dark:hover:text-primary-400 text-xs font-medium text-gray-400 dark:text-gray-300"
        >
            {{ $language->name }}
        </span>
    @endif
    <span
        class="hover:text-primary-600 dark:hover:text-primary-400 text-sm font-medium text-gray-400 dark:text-gray-300"
    >
        {{ $url }}
    </span>
</div>
