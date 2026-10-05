<div
    @class([
        'items-center gap-x-1',
        'flex' => $inSidebar ?? false,
        'hidden lg:flex' => ! ($inSidebar ?? false),
    ])
    data-capell-header-actions
>
    @livewire('capell-admin::header.admin-workspace-switcher', ['inSidebar' => $inSidebar ?? false])
    @livewire('capell-admin::header.admin-tools', ['inSidebar' => $inSidebar ?? false])
</div>
