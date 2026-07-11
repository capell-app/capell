@php
    use Filament\Schemas\Components\Tabs\Tab;
    use Filament\Support\Facades\FilamentAsset;

    $activeTab = $getActiveTab();
    $isContained = $isContained();
    $isCollapsible = $isCollapsible();
    $isVertical = $isVertical();
    $label = $getLabel();
    $livewireProperty = $getLivewireProperty();
    $renderHookScopes = $getRenderHookScopes();
    $id = $getId();
@endphp

@if (blank($livewireProperty))
    <div
        x-load
        x-load-src="{{ FilamentAsset::getAlpineComponentSrc('tabs', 'filament/schemas') }}"
        x-data="tabsSchemaComponent({
            activeTab: @js($activeTab),
            isScrollable: true,
            isTabPersistedInQueryString: @js($isTabPersistedInQueryString()),
            livewireId: @js($this->getId()),
            tab: @if ($isTabPersisted() && filled($id)) $persist(null).as(@js($id)) @else @js(null) @endif,
            tabQueryStringKey: @js($getTabQueryStringKey()),
        })"
        wire:ignore.self
        {{
            $attributes
                ->merge([
                    'id' => $id,
                    'wire:key' => $getLivewireKey() . '.container',
                ], escape: false)
                ->merge($getExtraAttributes(), escape: false)
                ->merge($getExtraAlpineAttributes(), escape: false)
                ->class([
                    'fi-sc-tabs',
                    'fi-contained' => $isContained,
                    'fi-vertical' => $isVertical,
                ])
        }}
    >
        <input
            type="hidden"
            value="{{
                collect($getChildSchema()->getComponents())
                    ->filter(static fn (Tab $tab): bool => $tab->isVisible())
                    ->map(static fn (Tab $tab) => $tab->getKey(isAbsolute: false))
                    ->values()
                    ->toJson()
            }}"
            x-ref="tabsData"
        />

        <x-filament::tabs
            :contained="$isContained"
            :label="$label"
            :vertical="$isVertical"
            x-cloak
        >
            @foreach ($getStartRenderHooks() as $startRenderHook)
                {{ FilamentView::renderHook($startRenderHook, scopes: $renderHookScopes) }}
            @endforeach

            @foreach ($getChildSchema()->getComponents() as $tab)
                @php
                    $tabKey = $tab->getKey(isAbsolute: false);
                    $tabBadge = $tab->getBadge();
                    $tabBadgeColor = $tab->getBadgeColor();
                    $tabBadgeIcon = $tab->getBadgeIcon();
                    $tabBadgeIconPosition = $tab->getBadgeIconPosition();
                    $tabBadgeTooltip = $tab->getBadgeTooltip();
                    $tabIcon = $tab->getIcon();
                    $tabIconPosition = $tab->getIconPosition();
                    $tabExtraAttributeBag = $tab->getExtraAttributeBag();
                    $tabHiddenJs = $tab->getHiddenJs();
                    $tabVisibleJs = $tab->getVisibleJs();
                    $tabVisibilityJs = match ([filled($tabHiddenJs), filled($tabVisibleJs)]) {
                        [true, true] => "(! ({$tabHiddenJs})) && ({$tabVisibleJs})",
                        [true, false] => "! ({$tabHiddenJs})",
                        [false, true] => $tabVisibleJs,
                        default => null,
                    };
                @endphp

                <x-filament::tabs.item
                    :alpine-active="'tab === \'' . $tabKey . '\''"
                    :badge="$tabBadge"
                    :badge-color="$tabBadgeColor"
                    :badge-icon="$tabBadgeIcon"
                    :badge-icon-position="$tabBadgeIconPosition"
                    :badge-tooltip="$tabBadgeTooltip"
                    :icon="$tabIcon"
                    :icon-position="$tabIconPosition"
                    :x-on:click="'tab = \'' . $tabKey . '\''"
                    :x-show="$tabVisibilityJs"
                    :x-cloak="$tabVisibilityJs !== null"
                    :attributes="$tabExtraAttributeBag"
                >
                    {{ $tab->getLabel() }}
                </x-filament::tabs.item>
            @endforeach

            @if ($isCollapsible)
            @endif

            @foreach ($getEndRenderHooks() as $endRenderHook)
                {{ FilamentView::renderHook($endRenderHook, scopes: $renderHookScopes) }}
            @endforeach
        </x-filament::tabs>

        @foreach ($getChildSchema()->getComponents() as $tab)
            @php
                $tabHiddenJs = $tab->getHiddenJs();
                $tabVisibleJs = $tab->getVisibleJs();
                $tabVisibilityJs = match ([filled($tabHiddenJs), filled($tabVisibleJs)]) {
                    [true, true] => "(! ({$tabHiddenJs})) && ({$tabVisibleJs})",
                    [true, false] => "! ({$tabHiddenJs})",
                    [false, true] => $tabVisibleJs,
                    default => null,
                };
            @endphp

            @if ($tabVisibilityJs)
                <div
                    x-show="{!! $tabVisibilityJs !!}"
                    x-cloak
                >
                    {{ $tab }}
                </div>
            @else
                {{ $tab }}
            @endif
        @endforeach
    </div>
@else
    @php
        $activeTab = (string) ($this->{$livewireProperty});
    @endphp

    <div
        {{
            $attributes
                ->merge([
                    'id' => $id,
                    'wire:key' => $getLivewireKey() . '.container',
                ], escape: false)
                ->merge($getExtraAttributes(), escape: false)
                ->class([
                    'fi-sc-tabs',
                    'fi-contained' => $isContained,
                    'fi-vertical' => $isVertical,
                ])
        }}
    >
        <x-filament::tabs
            :contained="$isContained"
            :label="$label"
            :vertical="$isVertical"
        >
            @foreach ($getStartRenderHooks() as $startRenderHook)
                {{ FilamentView::renderHook($startRenderHook, scopes: $renderHookScopes) }}
            @endforeach

            @foreach ($getChildSchema()->getComponents(withOriginalKeys: true) as $tabKey => $tab)
                @php
                    $tabBadge = $tab->getBadge();
                    $tabBadgeColor = $tab->getBadgeColor();
                    $tabBadgeIcon = $tab->getBadgeIcon();
                    $tabBadgeIconPosition = $tab->getBadgeIconPosition();
                    $tabBadgeTooltip = $tab->getBadgeTooltip();
                    $tabIcon = $tab->getIcon();
                    $tabIconPosition = $tab->getIconPosition();
                    $tabExtraAttributeBag = $tab->getExtraAttributeBag();
                    $tabKey = (string) $tabKey;
                @endphp

                <x-filament::tabs.item
                    :active="$activeTab === $tabKey"
                    :badge="$tabBadge"
                    :badge-color="$tabBadgeColor"
                    :badge-icon="$tabBadgeIcon"
                    :badge-icon-position="$tabBadgeIconPosition"
                    :badge-tooltip="$tabBadgeTooltip"
                    :icon="$tabIcon"
                    :icon-position="$tabIconPosition"
                    :wire:click="'$set(\'' . $livewireProperty . '\', ' . (filled($tabKey) ? ('\'' . $tabKey . '\'') : 'null') . ')'"
                    :attributes="$tabExtraAttributeBag"
                >
                    {{ $tab->getLabel() ?? $this->generateTabLabel($tabKey) }}
                </x-filament::tabs.item>
            @endforeach

            @foreach ($getEndRenderHooks() as $endRenderHook)
                {{ FilamentView::renderHook($endRenderHook, scopes: $renderHookScopes) }}
            @endforeach
        </x-filament::tabs>

        @foreach ($getChildSchema()->getComponents(withOriginalKeys: true) as $tabKey => $tab)
            {{ $tab->key($tabKey) }}
        @endforeach
    </div>
@endif
