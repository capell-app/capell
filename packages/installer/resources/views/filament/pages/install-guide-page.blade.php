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
        <form wire:submit="reviewPatches">
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
    @if ($review !== [])
        <x-filament::section
            :heading="__('capell-installer::install-guide.review_changes')"
        >
            @foreach ($review as $patch)
                <div data-patch-review="{{ $patch['id'] }}">
                    <h3>{{ $patch['label'] }}</h3>
                    <p>{{ $patch['status'] }}</p>
                    <p>{{ $patch['guidance'] }}</p>
                    @foreach ($patch['files'] as $file)
                        <h4>{{ $file['path'] }}</h4>
                        <p>{{ __('capell-installer::install-guide.preview_before') }}</p>
                        <pre
                            class="overflow-x-auto whitespace-pre-wrap"
                            >{{ $file['before'] }}</pre
                        >
                        <p>{{ __('capell-installer::install-guide.preview_after') }}</p>
                        <pre
                            class="overflow-x-auto whitespace-pre-wrap"
                            >{{ $file['after'] }}</pre
                        >
                        @if ($file['truncated'])
                            <p>{{ __('capell-installer::install-guide.preview_truncated') }}</p>
                        @endif
                    @endforeach
                </div>
            @endforeach
        </x-filament::section>
    @endif

    @if ($patchResults !== [])
        <x-filament::section
            :heading="__('capell-installer::install-guide.apply_results')"
        >
            @foreach ($patchResults as $result)
                <div data-patch-result="{{ $result['id'] }}">
                    <h3>{{ $result['label'] }}</h3>
                    <p>{{ $result['failed'] ? __('capell-installer::install-guide.patch_failed') : $result['status'] }}</p>
                    @if ($result['manualRequired'])
                        <p>{{ __('capell-installer::install-guide.preview_manual') }}</p>
                    @endif
                    @foreach ($result['createdPaths'] as $path)
                        <p>{{ __('capell-installer::install-guide.recovery_created_file', ['path' => $path]) }}</p>
                    @endforeach
                    @foreach ($result['recoveryPaths'] as $path)
                        <p>{{ __('capell-installer::install-guide.recovery_file', ['path' => $path]) }}</p>
                    @endforeach
                </div>
            @endforeach
        </x-filament::section>
    @endif
</x-filament-panels::page>
