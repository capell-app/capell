@if ($groups !== [])
    <section
        class="grid gap-6"
        aria-labelledby="settings-hub-heading"
    >
        <div>
            <h2
                id="settings-hub-heading"
                class="text-base font-semibold text-gray-950 dark:text-white"
            >
                {{ __('capell-admin::generic.settings_hub_heading') }}
            </h2>
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ __('capell-admin::generic.settings_hub_description') }}
            </p>
        </div>

        @foreach ($groups as $hubGroup)
            <div class="grid gap-3">
                <h3
                    class="text-sm font-medium text-gray-700 dark:text-gray-300"
                >
                    {{ $hubGroup->group->getLabel() }}
                </h3>
                <ul class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($hubGroup->entries as $entry)
                        <li>
                            <a
                                href="{{ $entry->url }}"
                                class="flex h-full flex-col gap-1 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 transition hover:ring-primary-500 dark:bg-gray-900 dark:ring-white/10"
                            >
                                <span
                                    class="flex items-center justify-between gap-2"
                                >
                                    <span
                                        class="text-sm font-medium text-gray-950 dark:text-white"
                                        >{{ $entry->label }}</span
                                    >
                                    @if ($entry->configured === true)
                                        <x-filament::badge
                                            color="success"
                                            size="sm"
                                            >{{ __('capell-admin::generic.settings_hub_configured') }}</x-filament::badge
                                        >
                                    @elseif ($entry->configured === false)
                                        <x-filament::badge
                                            color="warning"
                                            size="sm"
                                            >{{ __('capell-admin::generic.settings_hub_not_configured') }}</x-filament::badge
                                        >
                                    @endif
                                </span>
                                @if ($entry->description !== null)
                                    <span
                                        class="text-sm text-gray-600 dark:text-gray-400"
                                        >{{ $entry->description }}</span
                                    >
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </section>
@endif
