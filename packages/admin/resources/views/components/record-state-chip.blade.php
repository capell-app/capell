@props(['state'])

@php
    $icon = $state->icon;
    $label = $state->shortLabel ?? $state->label;
@endphp

<span
    @class([
        'inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium',
        'bg-danger-50 text-danger-700 dark:bg-danger-400/10 dark:text-danger-300' => $state->color === 'danger',
        'bg-warning-50 text-warning-700 dark:bg-warning-400/10 dark:text-warning-300' => $state->color === 'warning',
        'bg-info-50 text-info-700 dark:bg-info-400/10 dark:text-info-300' => $state->color === 'info',
        'bg-success-50 text-success-700 dark:bg-success-400/10 dark:text-success-300' => $state->color === 'success',
        'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-300' => ! in_array($state->color, ['danger', 'warning', 'info', 'success'], true),
    ])
    @if ($state->description)
        title="{{ $state->description }}"
    @endif
>
    @if ($icon)
        <x-filament::icon
            :icon="$icon"
            class="h-3.5 w-3.5"
            aria-hidden="true"
        />
    @endif

    <span>{{ $label }}</span>

    @if ($state->description)
        <span class="sr-only">{{ $state->description }}</span>
    @endif
</span>
