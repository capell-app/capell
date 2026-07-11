<?php
use Capell\Frontend\Facades\Frontend;
use Capell\Frontend\Support\View\PublicModelMeta;

$theme = Frontend::theme();
$roundedImages = (bool) PublicModelMeta::get($theme, 'rounded_images', false);

?>

@props([
    'height' => null,
    'lightbox' => false,
    'loop' => null,
    'media',
    'media_type' => null,
    'preview' => null,
    'rounded' => $roundedImages,
    'size' => null,
    'sizes' => null,
    'srcset' => null,
    'square' => false,
    'alt' => null,
    'fetchpriority' => null,
    'decoding' => 'async',
    'width' => null,
    'loading' => null,
])
{{-- format-ignore-start --}}
@php

    use Capell\Core\Contracts\Media\MediaContract;
    use Capell\Core\Enums\MediaConversionEnum;
    use Capell\Core\Models\Media as CapellMedia;

    /** @var MediaContract $media */
    throw_unless($media instanceof MediaContract, InvalidArgumentException::class, '"media" must be an instance of ' . MediaContract::class . ', ' . $media::class . ' given.');

    $conversionDimensions = MediaConversionEnum::defaultDimensionsByConversionValue();

    $conversionWidths = collect($conversionDimensions)
        ->map(function (array $dimensions): int {
            return $dimensions['width'];
        })
        ->all();

    $sizeAliases = [
        'thumb' => MediaConversionEnum::Thumbnail->value,
    ];

    $normalizedSize = is_string($size) ? ($sizeAliases[$size] ?? $size) : null;
    $sizeConversion = is_string($normalizedSize) ? MediaConversionEnum::tryFrom($normalizedSize) : null;

    if (! $width && ! $height) {
        if ($sizeConversion instanceof MediaConversionEnum) {
            $width = $sizeConversion->defaultWidth();
            $height = $sizeConversion->defaultHeight();
        } else {
            [$width, $height] = [
                $media->getCustomProperty('width', 600),
                $media->getCustomProperty('height', 400),
            ];
        }
    }

    if ($alt === null && $media instanceof CapellMedia) {
        $localizedAlt = $media->getAltText(Frontend::language());

        $alt = $localizedAlt ?? $media->getName();
    }

    $alt ??= $media->getName();

    $preferredConversion = $sizeConversion instanceof MediaConversionEnum
        ? $sizeConversion->value
        : null;

    $targetWidth = is_numeric($width) ? (int) $width : null;

    if ($targetWidth !== null && $targetWidth > 0 && $preferredConversion === null) {
        foreach ($conversionWidths as $conversionName => $conversionWidth) {
            if ($conversionWidth >= $targetWidth) {
                $preferredConversion = $conversionName;
                break;
            }
        }

        $preferredConversion ??= MediaConversionEnum::Large->value;
    }

    $availableConversions = array_keys($conversionWidths);

    if ($preferredConversion !== null) {
        $preferredIndex = array_search($preferredConversion, $availableConversions, true);

        if ($preferredIndex !== false) {
            $higherOrEqual = array_slice($availableConversions, $preferredIndex);
            $lower = array_reverse(array_slice($availableConversions, 0, $preferredIndex));
            $availableConversions = array_merge($higherOrEqual, $lower);
        }
    }

    $imageSrc = $media->getAvailableFullUrl($availableConversions);

    $conversionSrcset = collect($conversionWidths)
        ->map(function (int $conversionWidth, string $conversionName) use ($media): ?string {
            if (! $media->hasConversion($conversionName)) {
                return null;
            }

            return $media->getFullUrl($conversionName) . ' ' . $conversionWidth . 'w';
        })
        ->filter()
        ->implode(', ');

    $resolvedSrcset = null;

    if ($media->hasResponsiveImages()) {
        $resolvedSrcset = $media->getSrcset();
    } elseif ($conversionSrcset !== '') {
        $resolvedSrcset = $conversionSrcset;
    } elseif ($srcset) {
        $resolvedSrcset = is_array($srcset) ? implode(',', $srcset) : $srcset;
    }

    $lcpMediaUrl = Frontend::getFrontendData('lcpMediaUrl');
    if ($fetchpriority === null && is_string($lcpMediaUrl) && $lcpMediaUrl === $imageSrc) {
        $fetchpriority = 'high';
        $loading ??= 'eager';
    }
@endphp
{{-- format-ignore-end --}}
@if ($media_type === 'video' && $preview)
    <div class="capell-component capell-media-index relative h-full">
        <video
            data-group="{{ $loop ? 'gallery-' . $loop->parent->iteration : 'gallery' }}"
            data-type="video"
            data-lightbox="{{ asset('storage/' . $media->getPath()) }}"
            poster="{{ $preview instanceof MediaContract ? $preview->getFullUrl() : '' }}"
            width="{{ $width }}"
            height="{{ $height }}"
            class="{{
                collect([
                    'capell-component capell-media-index',
                    'lightbox cursor-pointer transform object-cover',
                    $rounded && $size !== 'lg' ? 'rounded' : null,
                    $rounded && $size === 'lg' ? 'rounded-lg' : null,
                    $rounded === 'full' ? 'rounded-full' : null,
                    $square ? 'aspect-square' : null,
                ])->filter()->implode(' ')
            }} {{ $attributes->get('class') }}"
            controls
            preload="none"
            alt="{{ $alt }}"
            @if ($loading)
                loading="{{ $loading }}"
            @endif
        >
            <source
                src="{{ $media->getFullUrl() }}"
                type="{{ $media->getMimeType() }}"
            />
            {{ __('Your browser does not support the video tag.') }}
        </video>
        @svg('heroicon-s-play', [
            'class' => 'text-secondary focus:text-primary pointer-events-none absolute top-1/2 left-1/2 h-20 w-20 -translate-x-1/2 -translate-y-1/2 rounded-full bg-white bg-white/80 p-4 opacity-75 hover:opacity-100 focus:opacity-100',
        ])
    </div>
@else
    <img
        src="{{ $imageSrc }}"
        @if ($resolvedSrcset)
            srcset="{{ $resolvedSrcset }}"
        @endif
        @if ($sizes)
            sizes="{{ $sizes }}"
        @endif
        width="{{ $width }}"
        height="{{ $height }}"
        alt="{{ $alt }}"
        decoding="{{ $decoding }}"
        @if ($fetchpriority)
            fetchpriority="{{ $fetchpriority }}"
        @endif
        @if ($loading)
            loading="{{ $loading }}"
        @endif
        @if ($lightbox)
            role="button"
            tabindex="0"
            aria-label="{{ __('capell-frontend::generic.open_image') }}: {{ $alt }}"
        @endif
        {{
            $attributes->class([
                $rounded && $size !== 'lg' ? 'rounded' : null,
                'capell-component capell-media-index',
                $rounded && $size === 'lg' ? 'rounded-lg' : null,
                $rounded === 'full' ? 'rounded-full' : null,
                $lightbox ? 'lightbox cursor-pointer' : null,
                $square ? 'aspect-square' : null,
            ])
        }}
    />
@endif
