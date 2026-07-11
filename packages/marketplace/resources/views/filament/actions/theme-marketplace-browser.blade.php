@php
    use Capell\Marketplace\Enums\ExtensionKind;
@endphp

<div class="space-y-4">
    <livewire:capell-marketplace.marketplace-extensions-browser
        locked-kind="{{ ExtensionKind::Theme->value }}"
    />
</div>
