@php
    use Capell\Frontend\Enums\RenderHookLocation;
    use Capell\Frontend\Facades\Frontend;
    use Capell\Frontend\Support\Render\RenderHookRegistry;
    use Capell\Frontend\Support\View\PublicModelMeta;
    use Illuminate\Support\HtmlString;

    $theme = Frontend::theme();
@endphp

@props([
    'author' => null,
    'color' => '',
    'count' => '',
    'hasBullet' => (bool) PublicModelMeta::get($theme, 'list_bullets', false),
    'headingSize' => null,
    'icon' => '',
    'image' => '',
    'linkText' => '',
    'loop' => '',
    'parent' => null,
    'publishDate' => null,
    'publishDatePosition' => 'bottom',
    'size' => '',
    'squareImage' => false,
    'summary' => '',
    'title' => '',
    'titleBalance' => false,
    'url' => '',
    'withSummary' => false,
])

@php
    $hasContent = ($withSummary && $summary) || $parent || $publishDate || $title || ($linkText && $url);
    $hasImage = (bool) $image;
    $parentPageUrl = $parent !== null && method_exists($parent, 'relationLoaded') && $parent->relationLoaded('pageUrl') ? $parent->pageUrl : null;
    $parentTranslation = $parent !== null && method_exists($parent, 'relationLoaded') && $parent->relationLoaded('translation') ? $parent->translation : null;
    $parentLabel = $parentTranslation?->label;
    $isBlogArticleCard = str_contains((string) $attributes->get('class'), 'capell-blog-article-card');
    $cardStyle = $isBlogArticleCard
        ? 'max-width: min(100%, calc(100vw - 3.75rem)); grid-template-columns: minmax(9.5rem, 10.5rem) minmax(0, 1fr);'
        : 'max-width: min(100%, calc(100vw - 3.75rem));';

    $publishDateOutput = static function (mixed $publishDate, string $class) use ($author): HtmlString {
        if (! $publishDate) {
            return new HtmlString('');
        }

        $formattedDate = $publishDate->format(config('capell-frontend.date_format'));
        $calendarIcon = svg('heroicon-o-calendar', 'mr-1.5 inline-block h-4 w-4 align-middle text-gray-500')->toHtml();
        $authorOutput = $author
            ? '<span class="ml-1 break-words">' . e(__('capell-frontend::generic.editor_by', ['name' => $author->name])) . '</span>'
            : '';

        return new HtmlString(
            '<time class="publish-date ' . e($class) . ' inline-flex items-center text-xs font-semibold uppercase leading-tight tracking-[0.06em] text-gray-500" title="' .
            e(__('capell-frontend::generic.publish_date', ['date' => $formattedDate])) .
            '" datetime="' . e($publishDate->toW3cString()) . '">' .
            '<span class="inline-flex items-center whitespace-nowrap">' .
            $calendarIcon .
            '<span>' . e($formattedDate) . '</span>' .
            '</span>' .
            $authorOutput .
            '</time>',
        );
    };
@endphp

<div
    {{
        $attributes->class([
            '@container/item capell-component capell-asset-index asset-item asset-index group w-full min-w-0 max-w-full overflow-hidden bg-white transition duration-200',
            'flex min-h-56 rounded-lg border border-slate-200 shadow-sm ring-1 ring-slate-950/5 hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-lg' => ! $isBlogArticleCard,
            'grid min-h-0 rounded-md border border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.05)] ring-1 ring-slate-950/5 hover:border-slate-300 hover:shadow-md' => $isBlogArticleCard,
        ])
            ->only(['class', 'role'])
            ->merge(['style' => $cardStyle])
    }}
>
    @if ($hasImage)
        <div
            @class([
                'asset-image w-full overflow-hidden bg-slate-100',
                'aspect-[16/9]' => ! $isBlogArticleCard,
                'h-full min-h-44' => $isBlogArticleCard,
            ])
        >
            @if ($url)
                <a
                    href="{{ $url }}"
                    title="{{ htmlspecialchars(strip_tags($title)) }}"
                    @wireNavigate
                >
                    <x-capell::image-source
                        :image="$image"
                        loading="lazy"
                        :alt="$title"
                        :width="720"
                        :height="405"
                        sizes="(min-width: 1024px) 30vw, (min-width: 768px) 45vw, 92vw"
                        :class="
                            'h-full w-full object-cover object-center transition duration-300 group-hover:scale-[1.03]' .
                            ((bool) PublicModelMeta::get($theme, 'rounded_images', false) ? ' rounded' : '') .
                            ($squareImage ? ' aspect-square' : '')
                        "
                    />
                </a>
            @else
                <x-capell::image-source
                    :image="$image"
                    loading="lazy"
                    :alt="$title"
                    :width="720"
                    :height="405"
                    sizes="(min-width: 1024px) 30vw, (min-width: 768px) 45vw, 92vw"
                    :class="
                        'h-full w-full object-cover object-center' .
                        ((bool) PublicModelMeta::get($theme, 'rounded_images', false) ? ' rounded' : '') .
                        ($squareImage ? ' aspect-square' : '')
                    "
                />
            @endif
        </div>
    @endif

    @if ($hasContent)
        <div
            @class([
                'asset-content relative flex min-w-0 grow flex-col overflow-hidden',
                'p-6' => ! $isBlogArticleCard,
                'p-5 xl:p-6' => $isBlogArticleCard,
                "before:content-['-'] before:absolute before:left-3 before:top-8 before:text-primary before:font-bold pl-8" => $hasBullet,
            ])
        >
            @if ($publishDate && $publishDatePosition === 'top')
                <div class="mb-5">
                    {{ $publishDateOutput($publishDate, 'whitespace-nowrap') }}
                </div>
            @endif

            @php
                $titleTag = $headingSize ?: null;
            @endphp

            {{-- format-ignore-start --}}
            @if ($titleTag)<{{ $titleTag }} class="font-heading mb-0">@endif
            {{-- format-ignore-end --}}
            @if ($url)
                <a
                    href="{{ $url }}"
                    title="{{ htmlspecialchars(strip_tags($title)) }}"
                    @class([
                        'block leading-tight font-semibold break-words text-slate-950 transition group-hover:text-blue-600 focus:text-blue-600',
                        'text-balance' => $titleBalance,
                        'text-xl md:text-2xl' => ! $size,
                        'text-2xl md:text-3xl' => $size === 'lg',
                        'text-lg md:text-xl' => $size === 'md',
                        'text-base md:text-lg' => $size === 'sm',
                    ])
                    @wireNavigate
                >
                    @if ($icon)
                        <x-dynamic-component
                            class="mr-1 -ml-1 inline-block h-4 w-4 shrink-0 align-middle text-slate-400"
                            :component="$icon"
                        />
                    @endif

                    {{ $title }}
                </a>
            @else
                <span
                    @class([
                        'block leading-tight font-semibold break-words text-slate-950',
                        'text-balance' => $titleBalance,
                        'text-xl md:text-2xl' => ! $size,
                        'text-2xl md:text-3xl' => $size === 'lg',
                        'text-lg md:text-xl' => $size === 'md',
                        'text-base md:text-lg' => $size === 'sm',
                    ])
                >
                    @if ($icon)
                        <x-dynamic-component
                            class="mr-1 -ml-1 inline-block h-4 w-4 shrink-0 align-middle text-slate-400"
                            :component="$icon"
                        />
                    @endif

                    {{ $title }}
                </span>
            @endif
            {{-- format-ignore-start --}}
            @if ($titleTag)</{{ $titleTag }}>@endif
            {{-- format-ignore-end --}}

            {!! app(RenderHookRegistry::class)->renderAll(RenderHookLocation::BeforeTitle, $item ?? null) !!}

            @if ($count)
                <span
                    class="mt-3 inline-flex w-fit items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500"
                >
                    {{ $count }}
                </span>
            @endif

            @if ($withSummary && $summary)
                <div
                    class="mt-4 line-clamp-3 w-full max-w-none overflow-hidden text-base leading-7 break-words text-slate-600 [&>:first-child]:mt-0 [&>:last-child]:mb-0"
                >
                    <p>
                        {{ $summary }}
                    </p>
                </div>
            @endif

            @if ($parentPageUrl && $parentLabel)
                <a
                    class="mt-4 line-clamp-2 block text-xs font-semibold tracking-[0.06em] break-words text-slate-500 uppercase transition hover:text-blue-600 focus:text-blue-600"
                    href="{{ $parentPageUrl->full_url }}"
                    title="{{ htmlspecialchars(strip_tags($parentLabel)) }}"
                    @wireNavigate
                >
                    {{ $parentLabel }}
                </a>
            @endif

            {!! app(RenderHookRegistry::class)->renderAll(RenderHookLocation::AfterTitle, $item ?? null) !!}

            <div class="mt-auto">
                @if ($publishDate && $publishDatePosition === 'bottom')
                    <div class="pt-6">
                        {{ $publishDateOutput($publishDate, 'whitespace-nowrap') }}
                    </div>
                @endif

                @if ($linkText && $url)
                    <a
                        @class([
                            'inline-flex items-center gap-2 pt-6 text-sm font-semibold text-blue-600 transition hover:text-blue-700 focus:text-blue-700',
                        ])
                        href="{{ $url }}"
                        aria-label="{{ __('capell-frontend::generic.read_more_about', ['title' => strip_tags((string) $title)]) }}"
                        @wireNavigate
                    >
                        {{ $linkText }}
                        @if (! $isBlogArticleCard)
                            @svg('heroicon-o-arrow-right', 'h-4 w-4 transition-transform group-hover:translate-x-0.5')
                        @endif
                    </a>
                @endif
            </div>
        </div>
    @endif

    {!! app(RenderHookRegistry::class)->renderAll(RenderHookLocation::Footer, $item ?? null) !!}
</div>
