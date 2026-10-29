@php($items = $this->items)
<x-filament-widgets::widget
    @class(['hidden' => $items === []])
    wire:poll.60s
>
    @if ($items !== [])
        <div
            role="status"
            class="rounded-lg border border-danger-200 bg-danger-50 px-4 py-3 text-sm dark:border-danger-800 dark:bg-danger-950"
        >
            <div class="flex items-center gap-2 font-medium">
                <x-filament::icon
                    icon="heroicon-o-exclamation-triangle"
                    class="h-4 w-4"
                />
                {{ trans_choice('capell-admin::work-first.urgent_count', count($items), ['count' => count($items)]) }}
            </div>
            <details class="mt-2">
                <summary class="cursor-pointer">
                    {{ __('capell-admin::work-first.urgent_details') }}
                </summary>
                <ul class="mt-2 space-y-2">
                    @foreach ($items as $item)
                        <li>
                            @if ($item->url)
                                <a
                                    href="{{ $item->url }}"
                                    class="underline"
                                    >{{ $item->title }}</a
                                >
                            @else
                                {{ $item->title }}
                            @endif
                            · {{ $item->siteName }} — {{ $item->description }}
                        </li>
                    @endforeach
                </ul>
            </details>
        </div>
    @endif
</x-filament-widgets::widget>
