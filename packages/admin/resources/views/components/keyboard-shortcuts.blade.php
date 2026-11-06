@props(['shortcuts' => []])

@php
    use Filament\Support\Facades\FilamentAsset;

    /** @var array<int, array{sequence?: string, key?: string, action: string, target: string, label: string, url?: string}> $shortcuts */
@endphp

@if (! empty($shortcuts))
    <div
        x-load
        x-load-src="{{ FilamentAsset::getAlpineComponentSrc('capell-keyboard-shortcuts', 'capell-admin') }}"
        x-data="capellKeyboardShortcuts(@js($shortcuts))"
        style="display: none"
        aria-hidden="true"
    ></div>
@endif
