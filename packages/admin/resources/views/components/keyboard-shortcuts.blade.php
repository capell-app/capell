@php
    use Filament\Support\Facades\FilamentAsset;

    /** @var array<int, array{sequence?: string, key?: string, action: string, target: string, label: string}> $shortcuts */
    $shortcuts = config('capell-admin.shortcuts', []);
@endphp

@if (! empty($shortcuts))
    <div
        x-load
        x-load-src="{{ FilamentAsset::getAlpineComponentSrc('capell-keyboard-shortcuts', 'capell-admin') }}"
        x-data="capellKeyboardShortcuts(@js($shortcuts))"
        x-init="init()"
        style="display: none"
        aria-hidden="true"
    ></div>
@endif
