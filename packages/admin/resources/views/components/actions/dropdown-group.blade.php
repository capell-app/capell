@php
    $hasActions = false;

    $group = $getGroup();

    foreach ($group->getActions() as $action) {
        if (! $action->isHiddenInGroup()) {
            $hasActions = true;
        }
    }
@endphp

@if ($group->isVisible() && $hasActions)
    <x-filament-actions::group
        class="fi-btn-group-dropdown"
        :button="true"
        :group="$group"
    />
@endif
