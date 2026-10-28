<li
    class="flex items-start gap-3 py-3"
    wire:key="attention-{{ $rowContext ?? 'preview' }}-{{ $item->key }}"
>
    <x-filament::icon
        :icon="$item->urgent ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-wrench-screwdriver'"
        class="mt-0.5 h-4 w-4 shrink-0"
    />
    <div class="min-w-0 flex-1">
        <p class="text-sm font-medium">{{ $item->title }} <span class="font-normal text-gray-500">· {{ $item->siteName }}</span></p>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $item->description }}</p>
        @if ($item->reasons !== [])
            <ul class="mt-1 text-xs text-gray-500">
                @foreach ($item->reasons as $reason)
                    <li>
                        {{ __('capell-admin::work-first.reason_' . $reason) }}
                    </li>
                @endforeach
            </ul>
        @endif
        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-2 text-xs">
            @if ($item->url)
                <a
                    href="{{ $item->url }}"
                    class="font-medium text-primary-600 hover:underline dark:text-primary-400"
                    >{{ __('capell-admin::work-first.review') }}</a
                >
            @else
                <span
                    class="text-gray-500"
                    >{{ __('capell-admin::work-first.contact_admin') }}</span
                >
            @endif
            @if (! $item->urgent)
                <button
                    type="button"
                    wire:click="dismiss(@js($item->key))"
                    class="text-gray-500 hover:underline"
                >
                    {{ __('capell-admin::work-first.dismiss') }}
                </button>
                <button
                    type="button"
                    wire:click="snooze(@js($item->key))"
                    class="text-gray-500 hover:underline"
                >
                    {{ __('capell-admin::work-first.snooze') }}
                </button>
            @endif
        </div>
    </div>
</li>
