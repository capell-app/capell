@php
    use Capell\Admin\Contracts\Support\FlagIconRenderer;
    use Capell\Admin\Enums\ResourceEnum;
    use Capell\Admin\Support\AdminSurfaceLookup;
    use Filament\Support\Icons\Heroicon;

    $flagIconRenderer = app(FlagIconRenderer::class);
    $currentSiteId = request()->integer('site') ?: (int) session('capell.current_site_id', 0);
    $currentSite = $currentSiteId > 0 ? $sites->firstWhere('id', $currentSiteId) : null;
@endphp

<x-filament::dropdown
    placement="bottom-end"
    :teleport="$inSidebar ?? false"
    :shift="$inSidebar ?? false"
    :size="$inSidebar ?? false"
    :class="($inSidebar ?? false) ? 'min-w-0 max-w-full' : 'block max-lg:hidden'"
    data-capell-site-switcher
>
    <x-slot name="trigger">
        <x-filament::button
            :icon="Heroicon::OutlinedBuildingStorefront"
            color="info"
            type="button"
            :aria-label="__('capell-admin::generic.site_switcher_label', ['site' => $currentSite?->name ?? __('capell-admin::generic.all_sites')])"
            :class="($inSidebar ?? false) ? 'max-w-full' : null"
        >
            <span @class(['block max-w-40 truncate' => $inSidebar ?? false])>
                {{ $currentSite?->name ?? __('capell-admin::generic.all_sites') }}
            </span>
        </x-filament::button>
    </x-slot>
    @foreach ($sites as $site)
        @php
            $isCurrentSite = $currentSiteId === (int) $site->id;
            $canEditSite = auth()->user()?->can('update', $site) ?? false;
        @endphp

        <x-filament::dropdown.header class="font-semibold">
            {{ $site->name }}
        </x-filament::dropdown.header>
        <x-filament::dropdown.list>
            @if ($isCurrentSite)
                <x-filament::dropdown.list.item
                    :icon="Heroicon::CheckCircle"
                    aria-current="true"
                >
                    {{ __('capell-admin::generic.current_site') }}
                </x-filament::dropdown.list.item>
            @else
                <x-filament::dropdown.list.item
                    :href="request()->fullUrlWithQuery(['site' => $site->id])"
                    :icon="Heroicon::OutlinedBuildingStorefront"
                    tag="a"
                >
                    {{ __('capell-admin::generic.use_site_in_admin', ['site' => $site->name]) }}
                </x-filament::dropdown.list.item>
            @endif

            @if ($canEditSite)
                <x-filament::dropdown.list.item
                    :href="AdminSurfaceLookup::resource(ResourceEnum::Site)::getUrl('edit', ['record' => $site->id])"
                    :icon="Heroicon::OutlinedCog6Tooth"
                    tag="a"
                >
                    {{ __('capell-admin::generic.edit_site_settings') }}
                </x-filament::dropdown.list.item>
            @endif
        </x-filament::dropdown.list>

        @if ($site->siteDomains->isNotEmpty())
            <x-filament::dropdown.list>
                @foreach ($site->siteDomains as $siteDomain)
                    <x-filament::dropdown.list.item
                        :href="$siteDomain->full_url"
                        :tooltip="$siteDomain->full_url"
                        tag="a"
                        target="_blank"
                    >
                        <span class="flex items-center gap-2">
                            @if ($siteDomain->language->flag)
                                {!!
                                    $flagIconRenderer->render(
                                        $siteDomain->language->flag,
                                        $siteDomain->language->name,
                                    )
                                !!}
                            @endif

                            <span>{{ $siteDomain->full_url }}</span>
                        </span>
                    </x-filament::dropdown.list.item>
                @endforeach
            </x-filament::dropdown.list>
        @endif
    @endforeach
</x-filament::dropdown>
