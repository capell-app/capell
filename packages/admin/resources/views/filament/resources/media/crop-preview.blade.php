@php
    use Capell\Core\Models\Media;
    use Capell\Core\Support\Media\MediaCropPresetRepository;

    /** @var Media|null $record */
    $isImage = $record instanceof Media && $record->isImage();
    $x = max(0, min(100, (int) ($get('focal_point_x') ?? 50)));
    $y = max(0, min(100, (int) ($get('focal_point_y') ?? 50)));
    $imageUrl = $isImage ? $record->getFullUrl() : null;
    $objectPosition = "{$x}% {$y}%";
    $presets = app(MediaCropPresetRepository::class)->all();
@endphp

<div class="space-y-4">
    @if (! $isImage)
        <div
            class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-8 text-center text-sm text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300"
        >
            {{ __('capell-admin::media.not_an_image') }}
        </div>
    @else
        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_18rem]">
            <div class="space-y-2">
                <div class="flex items-center justify-between gap-3">
                    <p
                        class="text-sm font-medium text-gray-950 dark:text-white"
                    >
                        {{ __('capell-admin::media.preview') }}
                    </p>

                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ $x }}%, {{ $y }}%
                    </p>
                </div>

                <div
                    class="relative overflow-hidden rounded-lg border border-gray-200 bg-gray-950 shadow-sm dark:border-white/10"
                >
                    <div class="aspect-video">
                        <img
                            src="{{ $imageUrl }}"
                            alt=""
                            class="h-full w-full object-cover"
                            style="object-position: {{ $objectPosition }}"
                        />
                    </div>

                    <div
                        class="pointer-events-none absolute inset-0 bg-[linear-gradient(to_right,rgba(255,255,255,.26)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,.26)_1px,transparent_1px)] bg-[size:33.333%_33.333%]"
                    ></div>

                    <div
                        class="pointer-events-none absolute h-8 w-8 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white shadow-[0_0_0_1px_rgba(17,24,39,.65),0_8px_24px_rgba(0,0,0,.25)]"
                        style="left: {{ $x }}%; top: {{ $y }}%"
                    >
                        <span
                            class="absolute top-0 left-1/2 h-full w-px -translate-x-1/2 bg-white"
                        ></span>
                        <span
                            class="absolute top-1/2 left-0 h-px w-full -translate-y-1/2 bg-white"
                        ></span>
                        <span
                            class="absolute top-1/2 left-1/2 h-2 w-2 -translate-x-1/2 -translate-y-1/2 rounded-full bg-white"
                        ></span>
                    </div>
                </div>

                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('capell-admin::media.preview_hint') }}
                </p>
            </div>

            @if ($presets !== [])
                <div class="space-y-2">
                    <p
                        class="text-sm font-medium text-gray-950 dark:text-white"
                    >
                        {{ __('capell-admin::media.preset_preview') }}
                    </p>

                    <div class="grid grid-cols-2 gap-3 lg:grid-cols-1">
                        @foreach ($presets as $preset)
                            <div
                                class="self-start overflow-hidden rounded-lg border border-gray-200 bg-gray-950 dark:border-white/10"
                            >
                                <div
                                    class="relative"
                                    style="
                                        aspect-ratio: {{ $preset['width'] }} /
                                            {{ $preset['height'] }};
                                    "
                                >
                                    <img
                                        src="{{ $imageUrl }}"
                                        alt=""
                                        class="h-full w-full object-cover"
                                        style="
                                            object-position: {{ $objectPosition }};
                                        "
                                    />

                                    <div
                                        class="pointer-events-none absolute h-3 w-3 -translate-x-1/2 -translate-y-1/2 rounded-full border border-white bg-gray-950/25"
                                        style="
                                            left: {{ $x }}%;
                                            top: {{ $y }}%;
                                        "
                                    ></div>
                                </div>

                                <div
                                    class="flex items-center justify-between gap-2 border-t border-white/10 px-3 py-2"
                                >
                                    <span
                                        class="truncate text-xs font-medium text-white"
                                    >
                                        {{ $preset['label'] }}
                                    </span>
                                    <span
                                        class="shrink-0 text-xs text-gray-300"
                                    >
                                        {{ $preset['ratio'] }}
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
