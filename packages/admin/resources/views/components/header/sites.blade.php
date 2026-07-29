@php
    use Capell\Admin\Contracts\Support\FlagIconRenderer;
    use Capell\Admin\Enums\ResourceEnum;
    use Capell\Admin\Support\AdminSurfaceLookup;
    use Filament\Support\Icons\Heroicon;

    $flagIconRenderer = app(FlagIconRenderer::class);
    $currentSiteId = request()->integer('site') ?: (int) session('capell.current_site_id', 0);
    $currentSite = $currentSiteId > 0 ? $sites->firstWhere('id', $currentSiteId) : null;
@endphp

<x-filament::dropdown placement="bottom-end">
    <x-slot name="trigger">
        <x-filament::button
            :icon="Heroicon::OutlinedBuildingStorefront"
            color="info"
            type="button"
        >
            {{ $currentSite?->name ?? __('capell-admin::generic.all_sites') }}
        </x-filament::button>
    </x-slot>
    @foreach ($sites as $site)
        <x-filament::dropdown.header
            class="font-semibold"
            tag="a"
            :href="AdminSurfaceLookup::resource(ResourceEnum::Site)::getUrl('edit', ['record' => $site->id])"
        >
            {{ $site->name }}
        </x-filament::dropdown.header>
        <x-filament::dropdown.list>
            <x-filament::dropdown.list.item
                :href="request()->fullUrlWithQuery(['site' => $site->id])"
                :icon="$currentSiteId === (int) $site->id ? Heroicon::CheckCircle : Heroicon::OutlinedBuildingStorefront"
                tag="a"
            >
                {{ __('capell-admin::generic.use_site_in_admin') }}
            </x-filament::dropdown.list.item>
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
