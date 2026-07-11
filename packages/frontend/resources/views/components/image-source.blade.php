@props([
    'image' => null,
    'media' => null,
    'height' => null,
    'width' => null,
    'alt' => null,
    'loading' => null,
    'decoding' => 'async',
    'fetchpriority' => null,
    'sizes' => null,
    'srcset' => null,
    'lightbox' => false,
])

@php
    use Capell\Core\Actions\ResolveImageSourceDataAction;
    use Capell\Core\Contracts\Media\MediaContract;
    use Capell\Core\Data\ImageSourceData;

    $source = $image ?? $media;

    if (! $source instanceof ImageSourceData) {
        $source = ResolveImageSourceDataAction::run($source, $source instanceof MediaContract ? $source : null, $alt);
    }
@endphp

@if ($source?->media instanceof MediaContract)
    <x-capell::media
        :media="$source->media"
        :$height
        :$width
        :alt="$alt ?? $source->alt"
        :$loading
        :$decoding
        :$fetchpriority
        :$sizes
        :$srcset
        :$lightbox
        {{ $attributes }}
    />
@elseif ($source?->isRenderable())
    <img
        src="{{ $source->url }}"
        @if ($srcset)
            srcset="{{ is_array($srcset) ? implode(',', $srcset) : $srcset }}"
        @endif
        @if ($sizes)
            sizes="{{ $sizes }}"
        @endif
        @if ($width ?? $source->width)
            width="{{ $width ?? $source->width }}"
        @endif
        @if ($height ?? $source->height)
            height="{{ $height ?? $source->height }}"
        @endif
        alt="{{ $alt ?? $source->alt ?? '' }}"
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
            aria-label="{{ __('capell-frontend::generic.open_image') }}: {{ $alt ?? $source->alt ?? '' }}"
        @endif
        {{
            $attributes->class([
                'capell-component capell-image-source',
                $lightbox ? 'lightbox cursor-pointer' : null,
            ])
        }}
    />
@endif
