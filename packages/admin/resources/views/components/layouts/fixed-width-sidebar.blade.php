<div
    {{ $attributes->merge(['class' => '@container fixed-sidebar contain-layout']) }}
    x-data="{ showModal: false }"
    x-init="
        window.addEventListener(
            'showModalUpdated',
            (e) => (showModal = e.detail.showModal),
        )
    "
>
    <div
        class="fixed-sidebar__wrapper flex max-w-full min-w-0 flex-wrap gap-6 @4xl:flex-nowrap"
    >
        <aside
            class="@container/sidebar fixed-sidebar__sidebar order-2 w-full max-w-full min-w-0 shrink-0 grow contain-layout @4xl:order-2 @4xl:grow-0 @4xl:basis-70"
            {{ $attributes->merge(['class' => 'fixed-sidebar__sidebar']) }}
        >
            <div :class="showModal ? '' : 'sticky top-20'">
                {{ $getChildSchema('sidebar') }}
            </div>
        </aside>
        <section
            class="fixed-sidebar__main order-1 max-w-full min-w-0 shrink grow basis-full @4xl:order-1 @4xl:basis-0"
        >
            <div :class="showModal ? '' : 'sticky top-20'">
                {{ $getChildSchema('main') }}
            </div>
        </section>
    </div>

    @script
        <script>
            Livewire.on(
                'sync-action-modals',
                ({ id, newActionNestingIndex }) => {
                    let showModal = newActionNestingIndex !== null
                    window.dispatchEvent(
                        new CustomEvent('showModalUpdated', {
                            detail: { showModal },
                        }),
                    )
                },
            )
        </script>
    @endscript
</div>
