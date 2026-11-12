<x-filament-panels::page>
    @if ($this->presets() === [])
        <p role="note">{{ __('capell-admin::setup.no_presets') }}</p>
    @elseif ($created)
        <section
            class="space-y-4"
            aria-live="polite"
        >
            <p>{{ __('capell-admin::setup.created') }}</p>
            <div class="flex flex-wrap gap-3">
                @if ($homeUrl)
                    <x-filament::button
                        tag="a"
                        :href="$homeUrl"
                    >
                        {{ __('capell-admin::setup.open_home') }}
                    </x-filament::button>
                @endif

                @unless ($sitePublished)
                    {{ $this->publishAction }}
                @endunless

                {{ $this->retryLogoAction }}
            </div>
            @if ($sitePublished)
                <p role="status">{{ __('capell-admin::setup.published') }}</p>
            @endif
        </section>
    @else
        <div class="grid gap-6 lg:grid-cols-2">
            <form
                wire:submit="createPages"
                class="space-y-4"
            >
                {{ $this->form }}
                <p wire:loading wire:target="createPages" role="status">
                    {{ __('capell-admin::setup.creating') }}
                </p>
            </form>

            @php($preview = $this->previewData())
            <aside
                aria-labelledby="guided-setup-preview"
                class="space-y-3 rounded-lg border p-4"
            >
                <h2
                    id="guided-setup-preview"
                    class="text-sm font-semibold"
                >
                    {{ __('capell-admin::setup.preview_heading') }}
                </h2>
                <p class="text-xs">{{ __('capell-admin::setup.preview_help') }}</p>
                <div
                    class="space-y-2 rounded border p-4"
                    @if ($preview['colour'] !== '') style="border-color: {{ $preview['colour'] }}" @endif
                >
                    <p
                        class="text-lg font-semibold"
                        @if ($preview['colour'] !== '') style="color: {{ $preview['colour'] }}" @endif
                    >
                        {{ $preview['name'] !== '' ? $preview['name'] : __('capell-admin::setup.preview_empty_name') }}
                    </p>
                    @if ($preview['tagline'] !== '')
                        <p>{{ $preview['tagline'] }}</p>
                    @endif

                    <nav
                        aria-label="{{ __('capell-admin::setup.preview_menu') }}"
                    >
                        <ul class="flex flex-wrap gap-3 text-sm">
                            @foreach ($preview['menu'] as $label)
                                <li>{{ $label }}</li>
                            @endforeach
                        </ul>
                    </nav>
                    <p class="text-sm">{{ $preview['intro'] }}</p>
                </div>
            </aside>
        </div>
    @endif
</x-filament-panels::page>
