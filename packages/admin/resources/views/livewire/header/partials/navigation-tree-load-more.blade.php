@php
    $level ??= 0;
    $padding = max(0, (int) $level - 1) * 1.5;
@endphp

@if ($branch['has_more'] ?? false)
    <button
        class="text-primary-600 hover:bg-primary-50 focus-visible:ring-primary-500 dark:text-primary-400 dark:hover:bg-primary-950/40 flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm font-medium transition outline-none focus-visible:ring-2 disabled:pointer-events-none disabled:opacity-70"
        style="padding-left: {{ $padding + 3.25 }}rem"
        type="button"
        wire:click="{{ $action }}"
        wire:loading.attr="disabled"
        wire:target="{{ $target }}"
    >
        <x-filament::loading-indicator
            class="h-4 w-4"
            wire:loading.delay
            wire:target="{{ $target }}"
        />
        <span
            wire:loading.remove
            wire:target="{{ $target }}"
        >
            {{ __('capell-admin::navigation_tree.load_more') }}
        </span>
    </button>
@endif
