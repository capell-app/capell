@php
    use Capell\Frontend\Actions\GetPageVariablesAction;
@endphp

@props([
    'headingSize' => 'h1',
    'title',
])
@php
    // Nested page context is not a text replacement for the translator.
    $variables = collect(GetPageVariablesAction::run())
        ->filter(static fn (mixed $value): bool => is_scalar($value) || $value instanceof Stringable)
        ->map(static fn (mixed $value): string => (string) $value)
        ->all();
@endphp
<{{ $headingSize }} class="capell-component capell-page-title">
    {{ __($title, $variables) }}
</{{ $headingSize }}>
