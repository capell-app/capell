<x-filament-widgets::widget>
    <x-filament::callout
        color="danger"
        icon="heroicon-o-wrench-screwdriver"
        :heading="__('capell-installer::widgets.not_installed_heading')"
        :description="__('capell-installer::widgets.not_installed_message')"
    >
        <x-slot name="controls">
            <x-filament::button
                :href="$this->installerUrl()"
                tag="a"
                color="danger"
                size="xl"
            >
                {{ __('capell-installer::widgets.install_action') }}
            </x-filament::button>
        </x-slot>
    </x-filament::callout>
</x-filament-widgets::widget>
