@php
    use Capell\Core\Enums\AssetEnum;
    use Capell\Core\Facades\CapellCore;
    use Filament\Pages\Page;

    $record = $getRecord();
    $livewire = $getLivewire();
    $recordUrl = '';
    if ($livewire instanceof Page) {
        $resource = $livewire->getResource();
        $recordUrl = $resource::getUrl('edit', ['record' => $record]);
    }
@endphp

<div
    @if ($recordUrl) x-on:click.stop="window.location = '{{ $recordUrl }}'" @endif
    {{
        $attributes->merge($getExtraAttributes())->class([
            'filament-table-column-assets relative z-0 flex min-h-full w-full items-center justify-center gap-2 px-4 py-3 text-xs leading-none font-light',
            'cursor-pointer' => $recordUrl,
        ])
    }}
>
    @if ($record->content_count)
        <x-filament::icon-button
            class="hover:bg-gray-400/10"
            :badge="$record->content_count ?: null"
            :icon="CapellCore::getAsset(Capell\Layout\Enums\AssetEnum::Content->value)->getIcon()"
            :tooltip="__('capell-admin::generic.contents')"
            color="gray"
            size="sm"
        />
    @endif

    @if ($record->pages_count)
        <x-filament::icon-button
            class="hover:bg-gray-400/10"
            :badge="$record->pages_count ?: null"
            :icon="CapellCore::getAsset(AssetEnum::Page)->getIcon()"
            :tooltip="__('capell-admin::generic.pages')"
            color="gray"
            size="sm"
        />
    @endif
</div>
