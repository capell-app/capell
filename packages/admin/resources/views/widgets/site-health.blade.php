@php
    use Capell\Admin\Enums\SetupHealthEnum;
@endphp

<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('capell-admin::dashboard.health_heading')"
    >
        @if ($this->allGood)
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('capell-admin::dashboard.health_all_good') }}
            </p>
        @else
            <div class="space-y-2">
                @foreach ($this->setupHealth->checks as $check)
                    <div class="flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            @if ($check->status === SetupHealthEnum::Green)
                                <span
                                    class="h-2.5 w-2.5 rounded-full bg-green-500"
                                ></span>
                            @elseif ($check->status === SetupHealthEnum::Amber)
                                <span
                                    class="h-2.5 w-2.5 rounded-full bg-amber-500"
                                ></span>
                            @else
                                <span
                                    class="h-2.5 w-2.5 rounded-full bg-red-500"
                                ></span>
                            @endif
                            <span class="text-sm">{{ $check->label }}</span>
                        </div>
                        @if ($check->fixUrl && $check->fixLabel)
                            <a
                                href="{{ $check->fixUrl }}"
                                class="text-primary-600 text-xs hover:underline"
                            >
                                {{ $check->fixLabel }}
                            </a>
                        @endif
                    </div>
                @endforeach

                @foreach ($this->contentHealth->issues as $issue)
                    <div class="flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            @if ($issue->count === 0)
                                <span
                                    class="h-2.5 w-2.5 rounded-full bg-green-500"
                                ></span>
                            @else
                                <span
                                    class="h-2.5 w-2.5 rounded-full bg-amber-500"
                                ></span>
                            @endif
                            <span class="text-sm">{{ $issue->label }}</span>
                        </div>
                        @if ($issue->count > 0 && $issue->filterUrl)
                            <a
                                href="{{ $issue->filterUrl }}"
                                class="text-primary-600 text-xs font-semibold hover:underline"
                            >
                                {{ $issue->count }}
                            </a>
                        @else
                            <span class="text-xs text-gray-400">
                                {{ $issue->count }}
                            </span>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
