@php
    use Capell\Admin\Contracts\Support\FlagIconRenderer;
    use Filament\Support\Enums\Alignment;

    $state = $getState();

    if (! $state) {
        return;
    }

    $flagIconRenderer = app(FlagIconRenderer::class);
@endphp

<span
    {{
        $attributes->merge($getExtraAttributes())->class([
            'filament-tables-flag-column flex w-full items-center',
            'px-4 py-3' => ! $isInline(),
            match ($getAlignment()) {
                Alignment::Left => 'justify-start',
                Alignment::Center => 'justify-center',
                Alignment::Right => 'justify-end',
                default => null,
            },
        ])
    }}
>
    @if ($state->flag)
        {!! $flagIconRenderer->render($state->flag, $state->name) !!}
    @else
        {{ $state->name }}
    @endif
</span>
