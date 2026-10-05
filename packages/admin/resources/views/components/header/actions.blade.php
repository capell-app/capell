<div
    @class([
        'items-center gap-x-1',
        'flex' => $inSidebar ?? false,
        'flex max-lg:hidden' => ! ($inSidebar ?? false),
    ])
    data-capell-header-actions
>
    @livewire('capell-admin::header.admin-workspace-switcher', ['inSidebar' => $inSidebar ?? false])
    @livewire('capell-admin::header.admin-tools', ['inSidebar' => $inSidebar ?? false])
</div>
