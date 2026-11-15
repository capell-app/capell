@php
    use Capell\Admin\Actions\ResolveFilamentIconAliasAction;
    use Illuminate\Support\Js;

    $pinned = $this->isPinned($item->key);
    $pinLabel = $pinned
        ? __('capell-admin::workspace.switcher_tools.unpin', ['tool' => $item->label])
        : __('capell-admin::workspace.switcher_tools.pin', ['tool' => $item->label]);
@endphp

<li
    wire:key="workspace-{{ $section }}-{{ $item->key }}"
    class="flex items-start gap-2 rounded-lg p-2 hover:bg-gray-50 dark:hover:bg-white/5"
>
    <a
        class="flex min-w-0 flex-1 items-start gap-3"
        href="{{ $item->url }}"
        wire:click="recordVisit({{ Js::from($item->key) }})"
        wire:navigate
        x-on:click="$dispatch('close-modal', { id: {{ Js::from($modalId) }} })"
    >
        <span
            class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400"
        >
            @if ($item->icon !== null)
                @svg(ResolveFilamentIconAliasAction::run($item->icon), 'h-4 w-4')
            @endif
        </span>
        <span class="min-w-0">
            <span
                class="block truncate text-sm font-medium text-gray-950 dark:text-white"
                >{{ $item->label }}</span
            >
            @if ($item->description !== null && $item->description !== '')
                <span
                    class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400"
                    >{{ $item->description }}</span
                >
            @endif
        </span>
    </a>
    <x-filament::icon-button
        :icon="$pinned ? 'heroicon-s-star' : 'heroicon-o-star'"
        :color="$pinned ? 'warning' : 'gray'"
        size="sm"
        :label="$pinLabel"
        :tooltip="$pinLabel"
        aria-pressed="{{ $pinned ? 'true' : 'false' }}"
        wire:click="togglePin({{ Js::from($item->key) }})"
        wire:loading.attr="disabled"
        wire:target="togglePin({{ Js::from($item->key) }})"
    />
</li>
