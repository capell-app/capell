@php
    use Capell\Admin\Contracts\Support\FlagIconRenderer;
    use Filament\Support\Enums\Alignment;

    $record = $getRecord();
    $flagIconRenderer = app(FlagIconRenderer::class);
@endphp

<div
    {{
        $attributes->merge($getExtraAttributes())->class([
            'filament-tables-language-flags-column flex items-center gap-2',
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
    @if (property_exists($record, 'language'))
        @if ($record->language->flag)
            {!!
                $flagIconRenderer->render(
                    $record->language->flag,
                    $record->language->name,
                    attributes: ['x-tooltip.raw' => $record->language->name],
                )
            !!}
        @else
            {{ $record->language->name }}
        @endif
    @elseif ($record->translations)
        @php($languages = $record->translations->pluck('language'))
        @foreach ($languages as $language)
            @if ($language->flag)
                {!!
                    $flagIconRenderer->render(
                        $language->flag,
                        $language->name,
                        attributes: ['x-tooltip.raw' => $language->name],
                    )
                !!}
            @else
                {{ $language->name }}
            @endif
        @endforeach
    @endif
</div>
