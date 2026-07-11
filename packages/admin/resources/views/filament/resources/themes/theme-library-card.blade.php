@php
    use Capell\Admin\Support\Themes\ThemeLibraryRuntime;
    use Capell\Core\Models\Theme;
    use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;

    $record = $getRecord();
    $runtime = app(ThemeLibraryRuntime::class);
    $card = $record instanceof Theme ? $runtime->installedCard($record) : null;
    $diagnostics = $record instanceof Theme ? $runtime->diagnostics($record->key, theme: $record) : null;
    $definition = $record instanceof Theme ? $runtime->definition($record->key) : null;
    $diagnosticErrors = $diagnostics ? collect($diagnostics->errors)->filter()->values() : collect();
    $diagnosticWarnings = $diagnostics ? collect($diagnostics->warnings)->filter()->values() : collect();
    $diagnosticMessages = $diagnosticErrors->merge($diagnosticWarnings)->values();
    $presetOptions = $definition instanceof ThemeDefinitionData ? $definition->presetOptions() : [];
    $activePreset = data_get($record?->meta, 'editor.preset.active');
    $activePresetLabel = is_string($activePreset) && $activePreset !== ''
        ? ($presetOptions[$activePreset] ?? str($activePreset)->headline()->toString())
        : null;
@endphp

@once
    <style>
        .capell-theme-card-record {
            padding: 0;
            position: relative;
        }

        .capell-theme-card-record.fi-ta-record-with-content-prefix {
            grid-template-columns: minmax(0, 1fr);
        }

        .capell-theme-card-record > .fi-ta-record-checkbox {
            position: absolute;
            inset-inline-start: 1rem;
            top: 1rem;
            z-index: 20;
        }

        .capell-theme-card-record > .fi-ta-record-content-ctn {
            min-width: 0;
            padding: 0;
        }

        .fi-ta-content-ctn
            .fi-ta-content
            .capell-theme-card-record
            > .fi-ta-record-content-ctn
            .fi-ta-record-content {
            padding: 0;
        }

        .capell-theme-card-record .fi-ta-actions {
            align-items: center;
            background: rgb(255 255 255 / 0.92);
            border-radius: 9999px;
            box-shadow: 0 1px 2px rgb(0 0 0 / 0.08);
            gap: 0.25rem;
            inset-inline-end: 0.75rem;
            margin: 0;
            padding: 0.25rem;
            position: absolute;
            top: 0.75rem;
            z-index: 10;
        }

        .dark .capell-theme-card-record .fi-ta-actions {
            background: rgb(3 7 18 / 0.82);
            box-shadow: 0 0 0 1px rgb(255 255 255 / 0.1);
        }

        .capell-theme-card-record .fi-ta-actions.fi-align-start {
            justify-content: flex-end;
        }

        .capell-theme-card-record .fi-ta-actions .fi-ac-action {
            min-height: 2rem;
            min-width: 2rem;
        }
    </style>
@endonce

@if ($record instanceof Theme && $card !== null)
    <div
        class="group overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-950/5 transition duration-150 hover:-translate-y-0.5 hover:shadow-md dark:bg-gray-900 dark:ring-white/10"
    >
        <div
            class="relative aspect-[16/10] w-full overflow-hidden bg-gray-100 dark:bg-gray-950"
        >
            @if (is_string($card->imageUrl) && $card->imageUrl !== '')
                <img
                    src="{{ $card->imageUrl }}"
                    alt="{{ __('capell-admin::theme-library.labels.preview_alt', ['theme' => $card->title]) }}"
                    class="h-full w-full object-cover transition duration-300 group-hover:scale-[1.02]"
                    loading="lazy"
                />
            @else
                <div
                    class="flex h-full w-full items-center justify-center bg-[radial-gradient(circle_at_top_left,rgba(var(--primary-500),0.18),transparent_34%),linear-gradient(135deg,rgba(37,99,235,0.82),rgba(15,23,42,0.86))] px-6 text-center"
                >
                    <div class="max-w-52">
                        <div class="text-5xl font-semibold text-white">
                            {{ mb_strtoupper(mb_substr($card->title, 0, 1)) }}
                        </div>
                    </div>
                </div>
            @endif

            <div
                class="absolute inset-x-0 bottom-0 h-20 bg-gradient-to-t from-black/55 to-transparent"
            ></div>

            <div class="absolute right-3 bottom-3 left-3 min-w-0">
                <h3 class="truncate text-base font-semibold text-white">
                    {{ $card->title }}
                </h3>
                <p class="mt-0.5 truncate text-xs text-white/75">
                    {{ $card->package }}
                </p>
            </div>
        </div>

        <div class="space-y-4 p-4">
            <div class="space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    @if ($card->active)
                        <span
                            class="bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-400/10 dark:text-success-300 rounded-md px-2 py-1 text-xs font-medium ring-1"
                        >
                            {{ __('capell-admin::theme-library.labels.active') }}
                        </span>
                    @endif

                    @if ($activePresetLabel !== null)
                        <span
                            class="bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-300 rounded-md px-2 py-1 text-xs font-medium ring-1"
                        >
                            {{ $activePresetLabel }}
                        </span>
                    @endif

                    @if ($diagnosticErrors->isNotEmpty())
                        <span
                            class="bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-300 rounded-md px-2 py-1 text-xs font-medium ring-1"
                        >
                            {{ __('capell-admin::theme-library.labels.diagnostics_error') }}
                        </span>
                    @elseif ($diagnosticWarnings->isNotEmpty())
                        <span
                            class="bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-300 rounded-md px-2 py-1 text-xs font-medium ring-1"
                        >
                            {{ __('capell-admin::theme-library.labels.diagnostics_warning') }}
                        </span>
                    @endif
                </div>

                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">
                    {{ __('capell-admin::theme-library.labels.sites') }}:
                    {{ $card->siteCount }}
                </p>

                <p class="text-sm text-gray-600 dark:text-gray-300">
                    {{ $card->description }}
                </p>

                @if ($diagnosticMessages->isNotEmpty())
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        {{ $diagnosticMessages->first() }}
                    </p>
                @endif
            </div>

            <details
                class="group/details border-t border-gray-100 pt-3 dark:border-white/10"
            >
                <summary
                    class="flex cursor-pointer list-none items-center justify-between gap-3 text-sm font-medium text-gray-700 marker:hidden dark:text-gray-200"
                >
                    <span class="flex items-center gap-2">
                        <span>
                            {{ __('capell-admin::theme-library.actions.show_details') }}
                        </span>
                    </span>
                    <span
                        class="text-xs font-medium text-gray-500 dark:text-gray-400"
                    >
                        {{ __('capell-admin::table.expand') }}
                    </span>
                </summary>

                <div class="mt-3 grid gap-2 text-sm">
                    <div
                        class="rounded-md bg-gray-50 px-3 py-2 text-gray-700 dark:bg-white/5 dark:text-gray-200"
                    >
                        {{ __('capell-admin::theme-library.labels.sites') }}:
                        {{ $card->siteCount }}
                    </div>

                    <div
                        class="rounded-md bg-gray-50 px-3 py-2 text-gray-700 dark:bg-white/5 dark:text-gray-200"
                    >
                        {{ __('capell-admin::theme-library.labels.package') }}:
                        {{ $card->package }}
                    </div>
                </div>
            </details>
        </div>
    </div>
@endif
