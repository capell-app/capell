@props([
    'color' => null,
    'icon' => null,
    'iconAnimation' => null,
    'iconVerticalAlignment' => 'center',
    'title' => null,
    'description' => null,
    'actions' => [],
    'actionsVerticalAlignment' => 'center',
    'border' => true,
])

@php
    use function Filament\Support\get_color_css_variables;

    use Illuminate\Support\Arr;

    $colors = Arr::toCssStyles([
        get_color_css_variables($color, shades: [50, 100, 400, 500, 700, 800]),
    ]);

    $iconClasses = Arr::toCssClasses([
        'h-8 w-8 text-custom-400',
        $iconAnimation,
    ]);
@endphp

<div
    x-data="{}"
    {{
        $attributes->class([
            'filament-capell-alert bg-custom-50 dark:bg-custom-400/10 rounded-md px-4 py-3',
            'ring-custom-100 ring-1 dark:ring-white/10' => $border,
        ])
    }}
    style="{{ $colors }}"
>
    <div class="flex gap-3">
        @if ($icon)
            <div
                @class([
                    'flex-shrink-0',
                    $iconVerticalAlignment === 'start' ? 'self-start' : 'self-center',
                ])
            >
                <x-filament::icon
                    :icon="$icon"
                    :class="$iconClasses"
                />
            </div>
        @endif

        <div
            class="flex-1 items-center space-y-3 md:flex md:justify-between md:gap-3 md:space-y-0"
        >
            @if ($title || $description)
                <div class="space-y-0.5">
                    @if ($title)
                        <p
                            class="text-custom-800 text-sm font-medium dark:text-white"
                        >
                            {{ $title }}
                        </p>
                    @endif

                    @if ($description)
                        <p class="text-custom-700 text-sm dark:text-white">
                            {{ $description }}
                        </p>
                    @endif
                </div>
            @endif

            @if ($actions)
                <div
                    @class([
                        'flex items-center gap-3',
                        $actionsVerticalAlignment === 'start' ? 'self-start' : 'self-center',
                    ])
                >
                    <div class="flex items-center gap-3 whitespace-nowrap">
                        @foreach ($actions as $action)
                            @if ($action->isVisible())
                                {{ $action }}
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
