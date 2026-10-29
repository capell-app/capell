<div
    x-data="{
        timer: null,
        form: null,
        handler: null,
        unavailable: false,
        init() {
            this.$nextTick(() => {
                this.form = document.querySelector('#form')
                if (!this.form) return
                this.handler = () => {
                    clearTimeout(this.timer)
                    this.timer = setTimeout(() => {
                        this.$wire.$commit().catch(() => { this.unavailable = true })
                    }, 750)
                }
                this.form.addEventListener('input', this.handler)
                this.form.addEventListener('change', this.handler)
            })
        },
        destroy() {
            clearTimeout(this.timer)
            this.form?.removeEventListener('input', this.handler)
            this.form?.removeEventListener('change', this.handler)
        }
    }"
>
    <p x-cloak x-show="unavailable" role="alert">{{ __('capell-admin::scratch_drafts.creation_unavailable') }}</p>
    @if ($draft = $this->creationRecovery)
        <section
            class="rounded-xl border border-gray-200 bg-white p-4"
            aria-label="{{ __('capell-admin::scratch_drafts.title') }}"
        >
            <p>{{ __('capell-admin::scratch_drafts.available', ['time' => $draft->saved_at->diffForHumans()]) }}</p>
            <div class="mt-3 flex gap-2">
                <x-filament::button
                    wire:click="restoreCreationRecovery({{ $draft->id }}, '{{ $draft->revision ?? '' }}', '{{ $draft->content_hash }}')"
                    wire:confirm="{{ __('capell-admin::scratch_drafts.restore_confirm') }}"
                    size="sm"
                    >{{ __('capell-admin::scratch_drafts.restore') }}</x-filament::button
                >
                <x-filament::button
                    wire:click="discardCreationRecovery({{ $draft->id }}, '{{ $draft->revision ?? '' }}', '{{ $draft->content_hash }}')"
                    wire:confirm="{{ __('capell-admin::scratch_drafts.discard_confirm') }}"
                    color="gray"
                    size="sm"
                    >{{ __('capell-admin::scratch_drafts.discard') }}</x-filament::button
                >
            </div>
        </section>
    @endif
</div>
