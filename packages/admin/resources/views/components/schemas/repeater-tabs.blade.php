@php
    use Capell\Admin\Contracts\Support\FlagIconRenderer;

    $items = $getItems();

    $addAction = $getAction($getAddActionName());
    $addAllAction = $getAction('add-all');
    $isAllAction = $addAllAction && $addAllAction->isVisible();
    $isCollapsible = $isCollapsible();
    $isAddable = $isAddable() && $addAction->isVisible();
    $isCompact = $isCompact();
    $isContained = $isContained();
    $isDeletable = $isDeletable();
    $hasItemLabels = $hasItemLabels();
    $fieldWrapperView = $getFieldWrapperView();
    $key = $getKey();
    $statePath = $getStatePath();

    $createItems = $getCreateItems();
    $activeTab = $getActiveTab();
    $label = $getLabel();
    $flagIconRenderer = app(FlagIconRenderer::class);
@endphp

<x-dynamic-component
    :component="$fieldWrapperView"
    :field="$field"
    x-data="{
        tab: {{ $activeTab }},
    }"
    x-on:refresh-tabs.window="
        $event.detail.statePath === '{{ $statePath }}'
            ? (tab = $event.detail.tabId)
            : null
    "
    x-cloak
    wire:key="{{ $this->getId() }}.{{ $statePath }}.{{ $field::class }}.nav"
    :attributes="
        $attributes->merge($getExtraAttributes(), escape: false)
            ->class([
                'fi-fo-repeater flex min-w-0 max-w-full flex-col gap-y-0',
            ])
    "
>
    <input
        x-model="tab"
        type="hidden"
    />
    @if ($items)
        <div
            @class([
                'fi-sc-tabs',
                'fi-contained' => $isContained,
                'flex max-w-full min-w-0 flex-col' => $isContained,
            ])
            wire:key=".repeater-tabs-container"
        >
            @if ($isAddable || count($items) > 1)
                <x-filament::tabs
                    :label="$label"
                    :contained="$isContained"
                    x-cloak
                >
                    @foreach ($items as $itemKey => $item)
                        @php
                            $tab = $getTabPresentation($itemKey);
                        @endphp

                        <x-filament::tabs.item
                            :alpine-active="'tab === ' . $loop->iteration"
                            :icon="$tab['isFlagIcon'] ? null : $tab['icon']"
                            :badge="$tab['badge']"
                            :badge-color="$tab['badgeColor']"
                            wire:key="{{ $this->getId() }}.{{ $item->getStatePath() }}.{{ $field::class }}.nav"
                            x-bind:aria-selected="tab === {{ $loop->iteration }}"
                            x-on:click.stop="tab = {{ $loop->iteration }}"
                            x-bind:tabindex="tab === {{ $loop->iteration }} ? 0 : -1"
                        >
                            <span
                                class="inline-flex min-w-0 items-center gap-1.5 whitespace-nowrap"
                            >
                                @if ($tab['isFlagIcon'])
                                    {!!
                                        $flagIconRenderer->render(
                                            $tab['icon'],
                                            $tab['label'],
                                            attributes: ['class' => 'text-sm'],
                                        )
                                    !!}
                                @endif

                                <span class="truncate">
                                    {{ $tab['label'] }}
                                </span>
                            </span>
                        </x-filament::tabs.item>
                    @endforeach

                    @if ($isAddable || $isDeletable)
                        <div class="ml-auto flex items-center">
                            <x-filament::dropdown placement="bottom-end">
                                <x-slot name="trigger">
                                    <x-filament::icon-button
                                        color="gray"
                                        icon="heroicon-m-ellipsis-vertical"
                                        size="sm"
                                        :label="__('capell-admin::generic.actions')"
                                    />
                                </x-slot>
                                <x-filament::dropdown.list>
                                    @if (count($items) > 1 && ($translateAction = $getAction('translate')) && $translateAction->isVisible())
                                        {{ $translateAction }}
                                    @endif

                                    @if ($isAddable && $createItems)
                                        <div
                                            class="relative"
                                            x-data="{
                                                isCollapsed: true,
                                            }"
                                        >
                                            <x-filament::dropdown.list.item
                                                x-on:click.stop="isCollapsed = ! isCollapsed"
                                                icon="heroicon-o-square-2-stack"
                                                icon-color="gray"
                                            >
                                                {{ __('filament-forms::components.repeater.actions.clone.label') }}
                                            </x-filament::dropdown.list.item>

                                            <div
                                                class="fi-dropdown-panel z-10 w-full rounded-lg bg-white ring-1 ring-gray-950/5 transition md:absolute md:top-0 md:right-full md:max-w-[14rem] md:shadow-lg dark:divide-white/5 dark:bg-gray-900 dark:ring-white/10"
                                                x-show="! isCollapsed"
                                            >
                                                <x-filament::dropdown.list>
                                                    @foreach ($createItems as $item)
                                                        {{ $getAction('cloneItem')->arguments(['key' => $key, 'language_id' => $item['id']])->label($item['label'])->icon($getCreateItemIcon($item)) }}
                                                    @endforeach
                                                </x-filament::dropdown.list>
                                            </div>
                                        </div>
                                    @endif

                                    @if ($isDeletable)
                                        {{ $getAction('deleteItem')->arguments(['key' => $key]) }}
                                    @endif

                                    @if ($isAddable && $createItems)
                                        <x-filament::dropdown
                                            placement="bottom"
                                        >
                                            <x-slot name="trigger">
                                                <x-filament::dropdown.list.item
                                                    :color="$addAction->getColor()"
                                                    :icon="$addAction->getIcon()"
                                                    size="sm"
                                                    outlined
                                                    wire:loading.attr="disabled"
                                                >
                                                    {{ $getAddActionLabel() }}
                                                </x-filament::dropdown.list.item>
                                            </x-slot>
                                            <x-filament::dropdown.list>
                                                {{ $isAllAction ? $addAllAction : null }}

                                                @foreach ($createItems as $createItem)
                                                    {{ $addAction->arguments(['language_id' => $createItem['id']])->grouped()->label($createItem['label'])->icon($getCreateItemIcon($createItem)) }}
                                                @endforeach
                                            </x-filament::dropdown.list>
                                        </x-filament::dropdown>
                                    @endif
                                </x-filament::dropdown.list>
                            </x-filament::dropdown>
                        </div>
                    @endif
                </x-filament::tabs>
            @endif

            @foreach ($items as $itemKey => $item)
                @php
                    $id = $this->getId() . '-' . $item->getStatePath();
                @endphp

                <div
                    x-bind:class="{
                        'fi-active': tab === @js($loop->iteration),
                    }"
                    x-on:expand="tab = @js($loop->iteration)"
                    x-cloak
                    {{
                        $attributes
                            ->merge([
                                'aria-labelledby' => $id,
                                'id' => $id,
                                'role' => 'tabpanel',
                                'tabindex' => '0',
                                'wire:key' => $id . '.' . $field::class . '.item',
                            ], escape: false)
                            ->merge($getExtraAttributes(), escape: false)
                            ->class([
                                'fi-sc-tabs-tab max-w-full min-w-0',
                            ])
                    }}
                >
                    <div
                        @class([
                            'max-w-full min-w-0' => $isContained,
                            'p-4' => $isContained && $isCompact,
                        ])
                    >
                        {{ $item }}
                    </div>
                </div>
            @endforeach
        </div>
    @elseif ($isAddable && $createItems)
        <div
            @class([
                'flex max-w-full min-w-0 flex-col gap-y-4',
                'p-4' => $isCompact,
            ])
        >
            <div class="flex flex-col gap-y-1">
                <h3
                    class="text-base leading-6 font-semibold text-gray-950 dark:text-white"
                >
                    {{ $label ?? __('capell-admin::generic.content') }}
                </h3>

                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('capell-admin::generic.repeater_tabs_empty_description') }}
                </p>
            </div>

            <div class="flex justify-start">
                <x-filament::dropdown
                    width="xs"
                    teleport
                    placement="top"
                >
                    <x-slot name="trigger">
                        <x-filament::button
                            :color="$addAction->getColor()"
                            :icon="$addAction->getIcon()"
                            size="sm"
                            outlined
                            wire:loading.attr="disabled"
                        >
                            {{ $getAddActionLabel() }}
                        </x-filament::button>
                    </x-slot>
                    <x-filament::dropdown.list>
                        {{ $isAllAction ? $addAllAction : null }}

                        @foreach ($createItems as $createItem)
                            {{ $addAction->arguments(['language_id' => $createItem['id']])->grouped()->label($createItem['label'])->icon($getCreateItemIcon($createItem)) }}
                        @endforeach
                    </x-filament::dropdown.list>
                </x-filament::dropdown>
            </div>
        </div>
    @endif
</x-dynamic-component>
