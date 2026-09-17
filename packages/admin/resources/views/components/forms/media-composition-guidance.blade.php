@if ($guidance === null)
    <div class="fi-sc-text text-sm text-danger-600 dark:text-danger-400" role="alert" data-media-composition-guidance-status="unregistered">
        {{ $warning }}
    </div>
@else
    <section
        class="space-y-2 rounded-lg border border-gray-200 p-3 text-sm text-gray-700 dark:border-white/10 dark:text-gray-300"
        aria-label="{{ $heading }}"
        data-media-composition-guidance
        data-media-composition-guidance-status="{{ $status }}"
    >
        <p class="font-medium text-gray-950 dark:text-white">
            {{ $heading }}
            @if ($dimensions !== null)
                <span class="font-normal text-gray-500 dark:text-gray-400">{{ $dimensions }}</span>
            @endif
        </p>

        <p>{{ $instructions }}</p>
        <p>{{ $cropDescription }}</p>

        @if ($layoutVariants !== null)
            <p>{{ $layoutVariants }}</p>
        @endif

        @if ($quietRegions !== [])
            <div>
                <p>{{ __('capell-admin::media.composition_guidance.quiet_regions') }}</p>
                <ul class="list-disc ps-5">
                    @foreach ($quietRegions as $region)
                        <li>{{ $region }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($warning !== null)
            <p class="text-danger-600 dark:text-danger-400" role="alert">{{ $warning }}</p>
        @elseif ($downloadUrl !== null)
            <a
                href="{{ $downloadUrl }}"
                download="{{ $downloadName }}"
                aria-label="{{ $downloadAccessibleName }}"
                class="font-medium text-primary-600 underline dark:text-primary-400"
            >{{ __('capell-admin::media.composition_guidance.download_label') }}</a>
        @endif
    </section>
@endif
