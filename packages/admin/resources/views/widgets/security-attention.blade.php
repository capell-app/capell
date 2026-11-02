<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('capell-admin::security.attention_title')"
    >
        <p>{{ __('capell-admin::security.attention_count', ['count' => $count]) }}</p>
        <a
            href="{{ $securityUrl }}"
            class="font-semibold underline"
            >{{ __('capell-admin::security.open_security') }}</a
        >
    </x-filament::section>
</x-filament-widgets::widget>
