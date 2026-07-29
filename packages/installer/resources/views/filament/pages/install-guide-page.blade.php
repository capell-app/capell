<x-filament-panels::page>
    <x-filament::callout
        color="info"
        icon="heroicon-o-information-circle"
        :heading="__('capell-installer::install-guide.intro_message')"
    />

    @if (empty($data['patches']))
        <x-filament::section>
            {{ __('capell-installer::install-guide.no_patches_available') }}
        </x-filament::section>
    @else
        <form wire:submit="applyPatches">
            @foreach ($data['patches'] as $groupName => $patches)
                <x-filament::section>
                    <x-slot name="heading">
                        {{ $groupName }}
                    </x-slot>

                    @foreach ($patches as $patch)
                        <x-filament::fieldset>
                            <x-slot name="label">
                                <label for="patch-{{ $patch['id'] }}">
                                    <x-filament::input.checkbox
                                        id="patch-{{ $patch['id'] }}"
                                        wire:model.live="data.selectedPatches"
                                        value="{{ $patch['id'] }}"
                                        :disabled="$patch['status']->value !== 'applicable'"
                                    />

                                    {{ $patch['label'] }}
                                </label>
                            </x-slot>

                            <x-filament::badge>
                                {{ $patch['status']->getLabel() }}
                            </x-filament::badge>

                            {{ $patch['description'] }}

                            @if ($patch['reason'])
                                <x-filament::callout color="warning">
                                    {{ $patch['reason'] }}
                                </x-filament::callout>
                            @endif

                            @if ($patch['docUrl'])
                                <x-filament::button
                                    :href="$patch['docUrl']"
                                    tag="a"
                                    target="_blank"
                                    color="gray"
                                >
                                    {{ __('capell-installer::install-guide.read_docs') }}
                                </x-filament::button>
                            @endif
                        </x-filament::fieldset>
                    @endforeach
                </x-filament::section>
            @endforeach
        </form>
    @endif
</x-filament-panels::page>
