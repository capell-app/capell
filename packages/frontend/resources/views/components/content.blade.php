@php
    use Capell\Core\Enums\ContentStructure;
    use Capell\Frontend\Actions\GetPageVariablesAction;
    use Capell\Frontend\Actions\RenderContentAction;
    use Capell\Frontend\Actions\RenderHtmlContentAction;
    use Capell\Frontend\Facades\Frontend;
    use Capell\Frontend\Support\View\PublicModelMeta;
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
    $page = $pageRecord;

    if ($page === null) {
        try {
            $page = Frontend::page();
        } catch (Throwable) {
            $page = null;
        }
    }

    if ($language === null) {
        try {
            $language = Frontend::language();
        } catch (Throwable) {
            $language = null;
        }
    }

    if ($site === null) {
        try {
            $site = Frontend::site();
        } catch (Throwable) {
            $site = null;
        }
    }

    if ($layout === null) {
        try {
            $layout = Frontend::layout();
        } catch (Throwable) {
            $layout = null;
        }
    }

    if ($theme === null) {
        try {
            $theme = Frontend::theme();
        } catch (Throwable) {
            $theme = null;
        }
    }

    $imageWidth = 360;
    $imageHeight = null;

    if ($image) {
        $sourceWidth = max(1, (int) $image->getWidth());
        $sourceHeight = max(1, (int) $image->getHeight());
        $imageHeight = (int) round($imageWidth * ($sourceHeight / $sourceWidth));
    }

    if (! $headingSize && ! $headingTag) {
        $headingSize = $muted ? $headingTag = 'h4' : $headingTag = 'h3';
    }

    if (! $headingTag) {
        $headingTag = $headingSize;
    }

    if (! $muted && $headingStyle === 'secondary') {
        $muted = true;
    }

    $pageVariables = GetPageVariablesAction::run(
        $page,
        $site,
        is_array($urlParams) ? $urlParams : [],
    );
    $translationVariables = collect($pageVariables)
        ->filter(fn (mixed $value): bool => is_scalar($value) || $value instanceof Stringable)
        ->map(fn (mixed $value): string => (string) $value)
        ->all();
    $roundedImages = (bool) PublicModelMeta::get($theme, 'rounded_images', false);

    if (is_string($content)) {
        $content = __($content, $translationVariables);
    }

    $title = __($title, $translationVariables);
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
                fetchpriority="high"
                @class([
                    'h-auto object-cover object-center md:float-right md:max-w-[40%] md:ml-10 md:mt-0',
                    'rounded' => $roundedImages,
                ])
                loading="eager"
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

    @if ($contentType === ContentStructure::Blocks)
        {!! RenderContentAction::run($content, ContentStructure::Blocks, ['layout' => $layout, 'page' => $page]) !!}
    @else
        {{ RenderHtmlContentAction::run($content, $pageVariables) }}
    @endif

    {{ $slot ?? '' }}

    @if ($divider === 'below_content')
        <div
            aria-hidden="true"
            class="mt-4 border-t border-gray-200"
        ></div>
    @endif
</div>
