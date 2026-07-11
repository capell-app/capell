@php
    use Capell\Core\Enums\MediaConversionEnum;
@endphp

@props([
    'height' => null,
    'media' => null,
    'image' => null,
    'width' => null,
])
<div
    class="capell-component capell-media-video relative min-h-full"
    x-data="{ play: false }"
    x-init="
        $watch('play', (value) => {
            if (value) {
                $refs.video.play()
            } else {
                $refs.video.pause()
            }
        })
    "
>
    <video
        class="aspect-video min-h-full object-cover object-top"
        playsinline
        x-ref="video"
        @click="play = !play"
        preload="none"
        @if ($width && $height)
            width="{{ $width }}"
            height="{{ $height }}"
        @endif
        @if ($image) poster="{{ $image->getUrl(MediaConversionEnum::Thumbnail->value) }}" @endif
    >
        <source
            src="{{ asset('storage/' . $media->getPath()) }}"
            type="{{ $media->getMimeType() }}"
        />
    </video>
    <div
        class="absolute inset-0 flex h-full w-full items-center justify-center"
        @click="play = true"
        x-show="!play"
        x-transition:leave="transition duration-300 ease-in"
        x-transition:leave-start="scale-100 transform opacity-100"
        x-transition:leave-end="scale-90 transform opacity-0"
    >
        @svg('heroicon-s-play', 'text-secondary hover:text-primary focus:text-primary h-20 w-20')
    </div>
</div>
