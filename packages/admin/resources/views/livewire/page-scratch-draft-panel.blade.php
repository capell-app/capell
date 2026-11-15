@php
    $draft = $this->draft;
@endphp

{{-- With nothing to restore the card stays out of the form; the "kept as you type" reassurance sits above Save instead. --}}
<div>
    @if ($draft)
        <section
            aria-label="{{ __('capell-admin::scratch_drafts.title') }}"
            class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900"
        >
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2
                        class="text-sm font-semibold text-gray-950 dark:text-white"
                    >
                        {{ __('capell-admin::scratch_drafts.title') }}
                    </h2>

                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                        {{ __('capell-admin::scratch_drafts.available', ['time' => $draft->saved_at->diffForHumans()]) }}
                    </p>
                </div>
            </div>

            <div class="mt-3 flex flex-wrap gap-2">
                <x-filament::button
                    wire:click="restore({{ $draft->id }}, '{{ $draft->revision ?? '' }}', '{{ $draft->content_hash }}')"
                    wire:confirm="{{ __('capell-admin::scratch_drafts.restore_confirm') }}"
                    size="sm"
                >
                    {{ __('capell-admin::scratch_drafts.restore') }}
                </x-filament::button>

                <x-filament::button
                    wire:click="discard({{ $draft->id }}, '{{ $draft->revision ?? '' }}', '{{ $draft->content_hash }}')"
                    wire:confirm="{{ __('capell-admin::scratch_drafts.discard_confirm') }}"
                    color="gray"
                    size="sm"
                >
                    {{ __('capell-admin::scratch_drafts.discard') }}
                </x-filament::button>
            </div>
        </section>
    @endif
</div>
