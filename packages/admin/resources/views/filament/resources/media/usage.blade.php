@php
    use Capell\Admin\Actions\Media\BuildMediaUsageItemsAction;
    use Capell\Core\Models\Media;

    /** @var Media|null $record */
    $items = BuildMediaUsageItemsAction::run($record instanceof Media ? $record : null);
@endphp

@if ($items === [])
    <p class="text-sm text-gray-500 dark:text-gray-400">
        {{ __('capell-admin::media.no_usage') }}
    </p>
@else
    <div
        class="divide-y divide-gray-200 overflow-hidden rounded-lg border border-gray-200 dark:divide-white/10 dark:border-white/10"
    >
        @foreach ($items as $item)
            <div class="flex items-center justify-between gap-3 px-4 py-3">
                <div class="min-w-0">
                    <p
                        class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400"
                    >
                        {{ $item['label'] }}
                    </p>
                    <p
                        class="truncate text-sm font-medium text-gray-950 dark:text-white"
                    >
                        {{ $item['title'] }}
                    </p>
                </div>

                @if ($item['url'] !== null)
                    <a
                        href="{{ $item['url'] }}"
                        class="text-primary-600 hover:text-primary-500 dark:text-primary-400 shrink-0 text-sm font-medium"
                    >
                        {{ __('capell-admin::media.open') }}
                    </a>
                @endif
            </div>
        @endforeach
    </div>
@endif
