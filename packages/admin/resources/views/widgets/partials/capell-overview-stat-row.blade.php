<div class="min-w-0 flex-1">
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
        <div
            class="text-sm leading-5 font-medium text-gray-950 dark:text-white"
        >
            {{ $stat->label }}
        </div>

        <div
            class="rounded-md bg-white px-1.5 py-0.5 text-[0.625rem] leading-4 font-medium text-gray-500 uppercase ring-1 ring-gray-200 dark:bg-white/10 dark:text-gray-400 dark:ring-white/10"
        >
            {{ $stat->group }}
        </div>
    </div>

    @if ($stat->description !== null)
        <div class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">
            {{ $stat->description }}
        </div>
    @endif
</div>

<div
    @class([
        'shrink-0 text-right text-xl leading-6 font-semibold text-gray-950 transition dark:text-white',
        'text-primary-600 dark:text-primary-400' => $stat->color === 'primary',
        'text-success-600 dark:text-success-400' => $stat->color === 'success',
        'text-warning-600 dark:text-warning-400' => $stat->color === 'warning',
        'text-danger-600 dark:text-danger-400' => $stat->color === 'danger',
        'group-hover:text-primary-600 dark:group-hover:text-primary-400' => $stat->url !== null,
    ])
>
    {{ $stat->value }}
</div>
