@php
    use Capell\Admin\Contracts\Support\FlagIconRenderer;
    use Capell\Core\Models\Language;
    use Filament\Support\Enums\Alignment;

    $record = $getRecord();

    /* @var class-string<\Capell\Core\Models\Language> $model */
    $model = Language::class;

    $name = $getName();

    $locales = json_decode($record->translated_locales);

    $languages = $model::whereIn('code', $locales)->ordered()->get();
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
            {{ $language->locale }}
        @endif
    @endforeach
</div>
