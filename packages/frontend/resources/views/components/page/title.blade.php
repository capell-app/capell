@props([
    'headingSize' => 'h1',
    'title',
])
<{{ $headingSize }} class="capell-component capell-page-title">
    {{ __($title, GetPageVariablesAction::run()) }}
</{{ $headingSize }}>
