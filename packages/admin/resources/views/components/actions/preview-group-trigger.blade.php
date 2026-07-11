<x-filament::button
    :color="$group->getColor()"
    :icon="$group->getIcon()"
    :icon-position="$group->getIconPosition()"
    :size="$group->getSize()"
    :tooltip="$group->getTooltip()"
    type="button"
    class="fi-ac-btn-group"
>
    {{ $group->getLabel() }}
</x-filament::button>
