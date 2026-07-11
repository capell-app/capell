<div class="text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
    {{ $stat->group }}
</div>

<div class="mt-2 flex items-start justify-between gap-3">
    <div>
        <div class="text-sm text-gray-600 dark:text-gray-300">
            {{ $stat->label }}
        </div>

        @if ($stat->description !== null)
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ $stat->description }}
            </div>
        @endif
    </div>

    <div
        @class([
            'text-2xl font-semibold text-gray-950 dark:text-white',
            'text-primary-600 dark:text-primary-400' => $stat->color === 'primary',
            'text-success-600 dark:text-success-400' => $stat->color === 'success',
            'text-warning-600 dark:text-warning-400' => $stat->color === 'warning',
            'text-danger-600 dark:text-danger-400' => $stat->color === 'danger',
        ])
    >
        {{ $stat->value }}
    </div>
</div>
