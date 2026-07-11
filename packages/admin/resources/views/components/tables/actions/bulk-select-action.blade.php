<x-filament::button
    class="filament-tables-bulk-action"
    :x-on:click="'mountAction(\'' . $getName() . '\')'"
    :icon="$getIcon()"
    :color="$getColor()"
    :attributes="\Filament\Support\prepare_inherited_attributes($getExtraAttributeBag())"
>
    {{ $getLabel() }}
</x-filament::button>
