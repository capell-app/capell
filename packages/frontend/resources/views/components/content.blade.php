@php
    use Capell\Core\Enums\ContentStructure;
    use Capell\Frontend\Actions\BuildPublicContentDataAction;
@endphp

@props([
    'align' => '',
    'contentType' => ContentStructure::Html,
    'color' => '',
    'compact' => false,
    'content' => '',
    'divider' => null,
    'headingSize' => null,
    'headingTag' => null,
    'headingStyle' => null,
    'headingWeight' => 'normal',
    'headingBalance' => true,
    'image' => null,
    'language' => null,
    'layout' => null,
    'muted' => null,
    'pageRecord' => null,
    'size' => '',
    'site' => null,
    'textAlign' => 'left',
    'theme' => null,
    'title' => '',
    'urlParams' => null,
    'width' => 'full',
])

@php
    $renderData = BuildPublicContentDataAction::run(
        content: $content,
        contentType: $contentType,
        title: $title,
        image: $image ?: null,
        pageRecord: $pageRecord,
        site: $site,
        layout: $layout,
        theme: $theme,
        urlParams: $urlParams,
    );
    $imageWidth = BuildPublicContentDataAction::IMAGE_WIDTH;
    $imageHeight = $renderData->imageHeight;
    $roundedImages = $renderData->roundedImages;
    $title = $renderData->title;

    if (! $headingSize && ! $headingTag) {
        $headingSize = $muted ? $headingTag = 'h4' : $headingTag = 'h3';
    }

    if (! $headingTag) {
        $headingTag = $headingSize;
    }

    if (! $muted && $headingStyle === 'secondary') {
        $muted = true;
    }
@endphp

<div
    {{
    $attributes->class([
        'content-component [&>:first-child]:mt-0 [&>:last-child]:mb-0',
        'capell-component capell-components-content',
        'max-w-none' => $width === 'full',
        'mx-auto' => $align === 'center' || (! $align && $textAlign === 'center'),
        'text-left' => $textAlign === 'left',
        'text-right' => $textAlign === 'right',
        'text-center' => $textAlign === 'center',
        $textAlign => ! in_array($textAlign, ['left', 'right', 'center'], true),
    ])
}}
>
    @if ($image)
        {{-- format-ignore-start --}}
        <x-capell::media
                :media="$image"
                fit="crop"
                :width="$imageWidth"
                :height="$imageHeight"
                :alt="$title"
                @class([
                    'h-auto object-cover object-center md:float-right md:max-w-[40%] md:ml-10 md:mt-0',
                    'rounded' => $roundedImages,
                ])
                sizes="(min-width: 768px) 40vw, 88vw"
        />
        {{-- format-ignore-end --}}
    @endif

    @if ($divider === 'above_heading' && $title)
        <div
            aria-hidden="true"
            class="mb-4 border-t border-gray-200"
        ></div>
    @endif

    @if ($title)
        {{-- format-ignore-start --}}
        <{{ $headingTag }}
            @class([
                'mb-4 text-balance text-lg',
                'text-4xl' => $headingSize === 'h1',
                'text-3xl' => $headingSize === 'h2',
                'text-2xl' => $headingSize === 'h3',
                'text-xl' => $headingSize === 'h4',
                'text-lg' => $headingSize === 'h5',
                'text-base' => $headingSize === 'h6',
                'font-medium' => $headingWeight === 'medium',
                'font-normal' => $headingWeight !== 'medium',
                'text-balance' => $headingBalance,
            ])
        >
            {{ $title }}
        </{{ $headingTag }}>
        {{-- format-ignore-end --}}
    @endif

    @if ($divider === 'below_heading' && $title)
        <div
            aria-hidden="true"
            class="mb-4 border-t border-gray-200"
        ></div>
    @endif

    {!! $renderData->bodyHtml !!}

    {{ $slot ?? '' }}

    @if ($divider === 'below_content')
        <div
            aria-hidden="true"
            class="mt-4 border-t border-gray-200"
        ></div>
    @endif
</div>
