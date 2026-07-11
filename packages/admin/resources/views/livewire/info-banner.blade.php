<div>
    @if ($this->visible)
        <div
            x-data="{ show: true }"
            x-show="show"
            x-transition:leave="transition duration-150 ease-in"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            @class([
                'flex items-start gap-3 border-b px-4 py-2.5 text-sm',
                'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-800 dark:bg-blue-950/50 dark:text-blue-200' => $this->tone === 'info',
                'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-200' => $this->tone === 'tip',
            ])
        >
            <x-filament::icon
                icon="heroicon-o-light-bulb"
                class="mt-0.5 h-4 w-4 flex-shrink-0 opacity-70"
            />

            <p class="flex-1">
                {{ $content }}
            </p>

            <button
                class="flex-shrink-0 opacity-50 transition-opacity hover:opacity-100"
                type="button"
                aria-label="{{ __('capell-admin::button.dismiss') }}"
                x-on:click="show = false"
                wire:click="dismiss"
            >
                <x-filament::icon
                    icon="heroicon-o-x-mark"
                    class="h-4 w-4"
                />
            </button>
        </div>
    @endif
</div>
