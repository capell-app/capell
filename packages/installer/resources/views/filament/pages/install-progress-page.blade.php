<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            {{ __('capell-installer::installer.progress_heading') }}
        </x-slot>

        <x-slot name="description">
            {{ __('capell-installer::installer.status_' . $installStatus) }}
        </x-slot>

        <x-filament::button
            :href="$this->reportUrl()"
            :download="$this->reportDownloadFilename()"
            tag="a"
            target="_blank"
            color="gray"
        >
            {{ __('capell-installer::installer.download_report') }}
        </x-filament::button>

        <x-filament::fieldset>
            <x-slot name="label">
                {{ __('capell-installer::installer.progress_heading') }}
            </x-slot>

            <div wire:poll.2s="$refresh">
                @forelse ($this->lines() as $line)
                    <p>{{ $line }}</p>
                @empty
                    <p>
                        {{ __('capell-installer::installer.waiting_for_output') }}
                    </p>
                @endforelse
            </div>
        </x-filament::fieldset>
    </x-filament::section>
</x-filament-panels::page>
