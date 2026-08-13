@php
    $draft = $this->draft;
@endphp

<section
    aria-label="{{ __('capell-admin::scratch_drafts.title') }}"
    class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900"
>
    <div class="flex items-start justify-between gap-3">
        <div>
            <h2 class="text-sm font-semibold text-gray-950 dark:text-white">
                {{ __('capell-admin::scratch_drafts.title') }}
            </h2>

            @if ($draft)
                <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                    {{ __('capell-admin::scratch_drafts.available', ['time' => $draft->saved_at->diffForHumans()]) }}
                </p>
            @else
                <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                    {{ __('capell-admin::scratch_drafts.none') }}
                </p>
            @endif
        </div>
    </div>

    @if ($draft)
        <div class="mt-3 flex flex-wrap gap-2">
            <x-filament::button
                wire:click="restore"
                size="sm"
            >
                {{ __('capell-admin::scratch_drafts.restore') }}
            </x-filament::button>

            <x-filament::button
                wire:click="discard"
                color="gray"
                size="sm"
            >
                {{ __('capell-admin::scratch_drafts.discard') }}
            </x-filament::button>
        </div>
    @endif
</section>
