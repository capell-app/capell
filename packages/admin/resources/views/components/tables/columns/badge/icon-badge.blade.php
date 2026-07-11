@php
    use Awcodes\BadgeableColumn\Enums\BadgeSize;
@endphp

@if (! $isHidden())
    @php
        use Illuminate\Support\Arr;

        $size = match (($size = $getSize())) {
            BadgeSize::ExtraSmall, 'xs' => 'xs',
            BadgeSize::Small, 'sm', null => 'sm',
            BadgeSize::Medium, 'base', 'md' => 'md',
            default => $size,
        };

        $badgeClasses = Arr::toCssClasses(['badgeable-column-badge badgeable-column-icon-badge']);
    @endphp

    <x-filament::icon-button
        :class="$badgeClasses"
        :color="$getColor()"
        :icon="$getIcon()"
        :icon-size="$getIconSize()"
        :badge="$getBadge()"
        :badgeColor="$getBadgeColor()"
        :badgeSize="$getIconSize()"
        :tooltip="$getLabel()"
        :$size
    />
@endif
