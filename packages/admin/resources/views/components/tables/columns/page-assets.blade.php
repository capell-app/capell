@php
    use Capell\Core\Actions\GetEditPageResourceUrlAction;
    use Capell\Core\Data\AssetData;
    use Capell\Core\Facades\CapellCore;
    use Filament\Pages\Page;

    $record = $getRecord();
    $livewire = $getLivewire();
    $recordUrl = '';
    if ($livewire instanceof Page) {
        $recordUrl = GetEditPageResourceUrlAction::run($record);
    }

    $relations = ['sections', 'pages'];

    foreach ($relations as $relation) {
        if ($record->getAttributeValue($relation) === null) {
            $record->loadCount($relation);
        }
    }
@endphp

<div
    @if ($recordUrl) x-on:click.stop="window.location = '{{ $recordUrl }}'" @endif
    {{
        $attributes->merge($getExtraAttributes())->class([
            'filament-table-column-page-badges relative z-0 flex min-h-full w-full items-center justify-center gap-2 px-4 py-3 text-xs leading-none font-light',
            'cursor-pointer' => $recordUrl,
        ])
    }}
>
    @php
        /** @var AssetData $assetData */
    @endphp

    @foreach (CapellCore::getAssets() as $assetData)
        @continue(! isset($record->{$assetData->name . '_count'}))
        <x-filament::icon-button
            class="hover:bg-gray-400/10"
            :badge="$assetData->name . '_count' ?: null"
            :icon="$assetData->getIcon()"
            :tooltip="$assetData->getLabel()"
            color="gray"
            size="sm"
        />
    @endforeach
</div>
