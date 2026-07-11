@php
    use Capell\Admin\Facades\CapellAdmin;
    use Capell\Admin\Filament\Pages\SiteHealthPage;
    use Capell\Admin\Filament\Pages\UpgradePage;
    use Capell\Core\Facades\CapellCore;
    use Filament\Support\Enums\IconSize;
    use Filament\Support\Icons\Heroicon;
@endphp

<div class="flex items-center">
    @if ($this->canViewTools())
        <x-filament::dropdown
            placement="bottom-end"
            x-on:close-dropdown="if ($event.detail.id === 'admin-tools-dropdown') close()"
        >
            <x-slot name="trigger">
                <button
                    @class([
                        'flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full',
                        'text-danger-600 ring-danger-500/70 dark:text-danger-400 ring-2' => $this->lockdownActive,
                    ])
                    type="button"
                    title="{{ $this->lockdownActive ? __('capell-admin::button.disable_lockdown_tooltip') : __('capell-admin::button.site_tools') }}"
                    x-tooltip.raw="{{ $this->lockdownActive ? __('capell-admin::message.lockdown_banner') : __('capell-admin::button.site_tools') }}"
                >
                    @svg(Heroicon::WrenchScrewdriver->getIconForSize(IconSize::Small), 'h-4 w-4')
                </button>
            </x-slot>
            <x-filament::dropdown.header
                class="overflow-hidden font-semibold"
                tag="a"
                href="https://capell.app"
                target="_blank"
                rel="noopener noreferrer"
            >
                <span
                    class="inline-block h-5 w-auto align-middle"
                    role="img"
                    aria-label="{{ __('capell-admin::generic.capell_tools') }}"
                >
                    @include('capell-admin::img.logo')
                </span>
                <x-filament::badge
                    color="gray"
                    size="sm"
                    class="float-right ml-2"
                >
                    {{ CapellCore::getInstalledPrettyVersion('capell-app/admin') }}
                </x-filament::badge>
            </x-filament::dropdown.header>

            <x-filament::dropdown.list>
                @if (CapellAdmin::settings()->enable_header_navigation_tree)
                    @livewire('capell-admin::header.navigation-tree', ['rowTrigger' => true])
                @endif

                <button
                    class="fi-dropdown-list-item fi-dropdown-list-item-color-gray flex w-full items-center gap-2 rounded-md p-2 text-sm whitespace-nowrap transition-colors duration-75 outline-none hover:bg-gray-50 focus:bg-gray-50 disabled:pointer-events-none disabled:opacity-70 dark:hover:bg-white/5 dark:focus:bg-white/5"
                    type="button"
                    wire:click="clearCache"
                    wire:loading.attr="disabled"
                    x-tooltip.raw="{{ __('capell-admin::button.clear_cache') }}"
                >
                    @svg(Heroicon::OutlinedTrash->getIconForSize(IconSize::Small), [
                        'class' => 'fi-dropdown-list-item-icon h-5 w-5 text-gray-400 dark:text-gray-500',
                        'wire:loading.remove.delay' => 1,
                        'wire:target' => 'clearCache',
                    ])

                    <x-filament::loading-indicator
                        class="fi-dropdown-list-item-icon h-5 w-5 text-gray-400 dark:text-gray-500"
                        wire:loading.delay
                        wire:target="clearCache"
                    />

                    {{ __('capell-admin::button.clear_cache') }}
                </button>

                <button
                    class="fi-dropdown-list-item fi-dropdown-list-item-color-gray flex w-full items-center gap-2 rounded-md p-2 text-sm whitespace-nowrap transition-colors duration-75 outline-none hover:bg-gray-50 focus:bg-gray-50 disabled:pointer-events-none disabled:opacity-70 dark:hover:bg-white/5 dark:focus:bg-white/5"
                    type="button"
                    title="{{ __('capell-admin::button.build_frontend_tooltip') }}"
                    wire:click="buildFrontend"
                    wire:loading.attr="disabled"
                    x-tooltip.raw="{{ __('capell-admin::button.build_frontend_tooltip') }}"
                >
                    @svg(Heroicon::BuildingStorefront->getIconForSize(IconSize::Small), [
                        'class' => 'fi-dropdown-list-item-icon h-5 w-5 text-gray-400 dark:text-gray-500',
                        'wire:loading.remove.delay' => 1,
                        'wire:target' => 'buildFrontend',
                    ])

                    <x-filament::loading-indicator
                        class="fi-dropdown-list-item-icon h-5 w-5 text-gray-400 dark:text-gray-500"
                        wire:loading.delay
                        wire:target="buildFrontend"
                    />

                    {{ __('capell-admin::button.build_frontend') }}
                </button>

                <button
                    class="fi-dropdown-list-item fi-dropdown-list-item-color-gray flex w-full items-center gap-2 rounded-md p-2 text-sm whitespace-nowrap transition-colors duration-75 outline-none hover:bg-gray-50 focus:bg-gray-50 disabled:pointer-events-none disabled:opacity-70 dark:hover:bg-white/5 dark:focus:bg-white/5"
                    type="button"
                    title="{{ __('capell-admin::button.rebuild_site_tooltip') }}"
                    wire:click="rebuildSite"
                    wire:loading.attr="disabled"
                    x-tooltip.raw="{{ __('capell-admin::button.rebuild_site_tooltip') }}"
                >
                    @svg(Heroicon::OutlinedWrenchScrewdriver->getIconForSize(IconSize::Small), [
                        'class' => 'fi-dropdown-list-item-icon h-5 w-5 text-gray-400 dark:text-gray-500',
                        'wire:loading.remove.delay' => 1,
                        'wire:target' => 'rebuildSite',
                    ])

                    <x-filament::loading-indicator
                        class="fi-dropdown-list-item-icon h-5 w-5 text-gray-400 dark:text-gray-500"
                        wire:loading.delay
                        wire:target="rebuildSite"
                    />

                    {{ __('capell-admin::button.rebuild_site') }}
                </button>

                @if ($this->lockdownActive)
                    <button
                        class="fi-dropdown-list-item fi-dropdown-list-item-color-gray flex w-full items-center gap-2 rounded-md p-2 text-sm whitespace-nowrap transition-colors duration-75 outline-none hover:bg-gray-50 focus:bg-gray-50 disabled:pointer-events-none disabled:opacity-70 dark:hover:bg-white/5 dark:focus:bg-white/5"
                        type="button"
                        title="{{ __('capell-admin::button.disable_lockdown_tooltip') }}"
                        wire:click="disableLockdown"
                        wire:confirm="{{ __('capell-admin::message.disable_lockdown_confirmation') }}"
                        wire:loading.attr="disabled"
                        x-tooltip.raw="{{ __('capell-admin::button.disable_lockdown_tooltip') }}"
                    >
                        @svg(Heroicon::OutlinedShieldCheck->getIconForSize(IconSize::Small), [
                            'class' => 'fi-dropdown-list-item-icon h-5 w-5 text-red-500',
                            'wire:loading.remove.delay' => 1,
                            'wire:target' => 'disableLockdown',
                        ])

                        <x-filament::loading-indicator
                            class="fi-dropdown-list-item-icon h-5 w-5 text-red-500"
                            wire:loading.delay
                            wire:target="disableLockdown"
                        />

                        {{ __('capell-admin::button.disable_lockdown') }}
                    </button>
                @else
                    <button
                        class="fi-dropdown-list-item fi-dropdown-list-item-color-danger text-danger-700 hover:bg-danger-50 focus:bg-danger-50 dark:text-danger-400 dark:hover:bg-danger-950/40 dark:focus:bg-danger-950/40 flex w-full items-center gap-2 rounded-md p-2 text-sm whitespace-nowrap transition-colors duration-75 outline-none disabled:pointer-events-none disabled:opacity-70"
                        type="button"
                        title="{{ __('capell-admin::button.enable_lockdown_tooltip') }}"
                        wire:click="enableLockdown"
                        wire:confirm="{{ __('capell-admin::message.enable_lockdown_confirmation') }}"
                        wire:loading.attr="disabled"
                        x-tooltip.raw="{{ __('capell-admin::button.enable_lockdown_tooltip') }}"
                    >
                        @svg(Heroicon::OutlinedShieldCheck->getIconForSize(IconSize::Small), [
                            'class' => 'fi-dropdown-list-item-icon text-danger-500 h-5 w-5',
                            'wire:loading.remove.delay' => 1,
                            'wire:target' => 'enableLockdown',
                        ])

                        <x-filament::loading-indicator
                            class="fi-dropdown-list-item-icon text-danger-500 h-5 w-5"
                            wire:loading.delay
                            wire:target="enableLockdown"
                        />

                        {{ __('capell-admin::button.enable_lockdown') }}
                    </button>
                @endif

                @foreach ($this->tools() as $tool)
                    {!! $tool->render() !!}
                @endforeach

                @if (SiteHealthPage::canAccess())
                    <a
                        class="fi-dropdown-list-item fi-dropdown-list-item-color-gray flex w-full items-center gap-2 rounded-md p-2 text-sm whitespace-nowrap transition-colors duration-75 outline-none hover:bg-gray-50 focus:bg-gray-50 dark:hover:bg-white/5 dark:focus:bg-white/5"
                        href="{{ SiteHealthPage::getUrl() }}"
                        title="{{ __('capell-admin::button.site_health_tooltip') }}"
                        x-tooltip.raw="{{ __('capell-admin::button.site_health_tooltip') }}"
                    >
                        @svg(Heroicon::OutlinedHeart->getIconForSize(IconSize::Small), [
                            'class' => 'fi-dropdown-list-item-icon h-5 w-5 text-gray-400 dark:text-gray-500',
                        ])

                        {{ __('capell-admin::navigation.site_health') }}
                    </a>
                @endif

                @if (UpgradePage::canAccess())
                    <a
                        class="fi-dropdown-list-item fi-dropdown-list-item-color-gray flex w-full items-center gap-2 rounded-md p-2 text-sm whitespace-nowrap transition-colors duration-75 outline-none hover:bg-gray-50 focus:bg-gray-50 dark:hover:bg-white/5 dark:focus:bg-white/5"
                        href="{{ UpgradePage::getUrl() }}"
                        title="{{ __('capell-admin::button.upgrade_capell_tooltip') }}"
                        x-tooltip.raw="{{ __('capell-admin::button.upgrade_capell_tooltip') }}"
                    >
                        @svg(Heroicon::OutlinedCloudArrowUp->getIconForSize(IconSize::Small), [
                            'class' => 'fi-dropdown-list-item-icon h-5 w-5 text-gray-400 dark:text-gray-500',
                        ])

                        {{ __('capell-admin::button.upgrade_capell') }}
                    </a>
                @endif
            </x-filament::dropdown.list>
        </x-filament::dropdown>
    @endif
</div>
