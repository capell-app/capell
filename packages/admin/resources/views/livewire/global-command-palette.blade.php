@php
    use Filament\Support\Enums\IconSize;
    use Filament\Support\Icons\Heroicon;
@endphp

<div
    x-data="{ open: @entangle('open') }"
    x-on:keydown.meta.k.window="$wire.toggle()"
    x-on:keydown.ctrl.k.window="$wire.toggle()"
    x-on:keydown.escape.window="
        if (open) {
            $wire.close()
        }
    "
>
    {{-- Backdrop --}}
    <div
        x-show="open"
        x-transition.opacity
        class="fixed inset-0 z-50 bg-black/40"
        style="display: none"
        x-on:click="$wire.close()"
    ></div>

    {{-- Palette modal --}}
    <div
        x-show="open"
        x-transition.scale.origin.top
        class="fixed inset-x-0 top-20 z-50 mx-auto max-w-xl px-4"
        style="display: none"
    >
        <div
            class="overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-gray-900/10 dark:bg-gray-900 dark:ring-gray-700"
        >
            {{-- Search input --}}
            <div
                class="flex items-center gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-800"
            >
                @svg(Heroicon::OutlinedMagnifyingGlass->getIconForSize(IconSize::Small), 'h-5 w-5 flex-shrink-0 text-gray-400')
                <input
                    class="flex-1 bg-transparent text-sm text-gray-800 placeholder-gray-400 outline-none dark:text-gray-200"
                    placeholder="{{ __('capell-admin::palette.search_placeholder') }}"
                    type="text"
                    wire:model.live="query"
                    x-ref="paletteInput"
                    x-on:keydown.escape.stop="$wire.close()"
                    x-init="
                        $watch('open', (value) => {
                            if (value) {
                                $nextTick(() => $refs.paletteInput.focus())
                            }
                        })
                    "
                />
                <kbd
                    class="hidden rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-500 sm:block dark:bg-gray-800 dark:text-gray-400"
                >
                    Esc
                </kbd>
            </div>

            {{-- Results --}}
            <ul
                class="max-h-80 overflow-y-auto py-2"
                role="listbox"
            >
                @forelse ($this->filteredCommands() as $command)
                    <li role="option">
                        @if ($command->url !== null)
                            <a
                                class="flex cursor-pointer items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-800"
                                href="{{ $command->url }}"
                                wire:navigate
                                x-on:click="$wire.close()"
                            >
                                @if ($command->icon !== null)
                                    @svg(is_string($command->icon) ? $command->icon : $command->icon->value, 'h-4 w-4 flex-shrink-0 text-gray-400')
                                @else
                                    <span class="h-4 w-4 flex-shrink-0"></span>
                                @endif

                                <span class="flex-1 truncate">
                                    {{ $command->label }}
                                </span>

                                @if ($command->shortcut !== null)
                                    <kbd
                                        class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-400 dark:bg-gray-800"
                                    >
                                        {{ $command->shortcut }}
                                    </kbd>
                                @endif
                            </a>
                        @else
                            <button
                                class="flex w-full cursor-pointer items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-800"
                                type="button"
                                x-on:click="{{ $command->alpineHandler !== null ? $command->alpineHandler . '; ' : '' }}$wire.close()"
                            >
                                @if ($command->icon !== null)
                                    @svg(is_string($command->icon) ? $command->icon : $command->icon->value, 'h-4 w-4 flex-shrink-0 text-gray-400')
                                @else
                                    <span class="h-4 w-4 flex-shrink-0"></span>
                                @endif

                                <span class="flex-1 truncate">
                                    {{ $command->label }}
                                </span>

                                @if ($command->shortcut !== null)
                                    <kbd
                                        class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-400 dark:bg-gray-800"
                                    >
                                        {{ $command->shortcut }}
                                    </kbd>
                                @endif
                            </button>
                        @endif
                    </li>
                @empty
                    <li class="px-4 py-6 text-center text-sm text-gray-400">
                        {{ __('capell-admin::palette.no_results') }}
                    </li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
