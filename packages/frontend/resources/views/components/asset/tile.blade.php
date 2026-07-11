@php
    use Capell\Frontend\Enums\RenderHookLocation;
    use Capell\Frontend\Facades\Frontend;
    use Capell\Frontend\Support\Render\RenderHookRegistry;
    use Capell\Frontend\Support\View\PublicModelMeta;

    $theme = Frontend::theme();
@endphp

@props([
    'author' => null,
    'headingSize' => 'h3',
    'icon' => '',
    'image' => '',
    'imagePosition' => 'left',
    'linkText' => '',
    'loop' => '',
    'parent' => null,
    'publishDate' => null,
    'publishDatePosition' => 'bottom',
    'size' => '',
    'squareImage' => false,
    'summary' => '',
    'title',
    'titleBalance' => false,
    'url' => '',
])

@php
    $imageOnTop = $imagePosition === 'top';
    $imageOnRight = $imagePosition === 'right';
    $imageInline = ! $imageOnTop && (bool) $image;
    $roundedImages = (bool) PublicModelMeta::get($theme, 'rounded_images', false);
    $parentPageUrl = $parent !== null && method_exists($parent, 'relationLoaded') && $parent->relationLoaded('pageUrl') ? $parent->pageUrl : null;
    $parentTranslation = $parent !== null && method_exists($parent, 'relationLoaded') && $parent->relationLoaded('translation') ? $parent->translation : null;
    $parentLabel = $parentTranslation?->label;
@endphp

<div
    {{
        $attributes->class([
            'capell-component capell-asset-tile asset-item asset-tile group @container/item overflow-hidden border border-slate-200 bg-white shadow-sm ring-1 ring-slate-950/5 transition duration-200 hover:border-slate-300 hover:shadow-md',
            'grid grid-cols-12' => $imageInline,
            'flex flex-col' => $imageOnTop || ! $image,
            'cursor-pointer' => $url,
            'rounded-lg' => $roundedImages,
        ])
    }}
>
    @if ($image)
        @php
            $imageWrapClass = match (true) {
                $imageOnTop => 'w-full',
                $imageOnRight => 'order-last col-span-4',
                default => 'col-span-4',
            };
            $imageClass = implode(' ', array_filter([
                'asset-tile-image w-full overflow-hidden object-cover object-center',
                ! $squareImage ? ($imageOnTop ? 'h-56' : 'h-48') : '',
                $squareImage ? 'aspect-square' : '',
                $roundedImages ? 'rounded' : '',
            ]));
        @endphp

        <div class="{{ $imageWrapClass }}">
            @if ($url)
                <a
                    href="{{ $url }}"
                    @wireNavigate
                >
                    <x-capell::media
                        :media="$image"
                        :alt="$title"
                        fit="contain"
                        height="{{ $imageOnTop ? 224 : 192 }}"
                        loading="lazy"
                        :class="$imageClass"
                    />
                </a>
            @else
                <x-capell::media
                    :media="$image"
                    :alt="$title"
                    fit="contain"
                    height="{{ $imageOnTop ? 224 : 192 }}"
                    loading="lazy"
                    :class="$imageClass"
                />
            @endif
        </div>
    @endif

    <div
        @class([
            'flex flex-1 flex-col p-6',
            'lg:px-8 lg:py-6' => $size !== 'sm',
            'col-span-8' => $imageInline && ! $imageOnRight,
            'order-first col-span-8' => $imageInline && $imageOnRight,
        ])
    >
        @if ($publishDate && $publishDatePosition === 'top')
            <time
                class="publish-date mb-2 inline-flex items-center text-xs leading-tight tracking-[0.02em] text-gray-600"
                title="{{ __('capell-frontend::generic.publish_date', ['date' => $publishDate->format(config('capell-frontend.date_format'))]) }}"
                datetime="{{ $publishDate->toW3cString() }}"
            >
                @svg('heroicon-o-calendar', 'mr-1 inline-block h-4 w-4 align-middle text-gray-400')
                {{ $publishDate->format(config('capell-frontend.date_format')) }}
                @if ($author)
                    <span class="ml-1">
                        {{ __('capell-frontend::generic.editor_by', ['name' => $author->name]) }}
                    </span>
                @endif
            </time>
        @endif

        {!!
            app(RenderHookRegistry::class)->renderAll(
                RenderHookLocation::BeforeContent,
                [
                    'item' => $item ?? null,
                ],
            )
        !!}

        <div
            @class([
                'max-w-none break-words [&>:first-child]:mt-0 [&>:last-child]:mb-0',
            ])
        >
            {{-- format-ignore-start --}}
            <{{ $headingSize }}
            @class([
                'font-heading',
                'mb-0' => ! $parent &&
                ! $summary,
                'text-md lg:text-lg' => $size === 'sm',
                'text-xl xl:text-2xl' => $size === 'lg',
                'text-balance' => $titleBalance,
            ])
            >
            @if ($url)
                <a
                        class="hover:text-primary focus:text-primary inline-block break-words font-semibold text-slate-950 no-underline transition"
                        href="{{ $url }}"
                        title="{{ htmlspecialchars(strip_tags($title)) }}"
                        @wireNavigate
                >
                    @if ($icon)
                        <x-dynamic-component
                                class="-ml-1 mr-0.5 inline-block h-4 w-4 shrink-0 align-middle text-gray-400"
                                :component="$icon"
                        />
                    @endif

                    {{ $title }}
                </a>
            @else
                @if ($icon)
                    <x-dynamic-component
                            class="-ml-1 mr-0.5 inline-block h-4 w-4 shrink-0 align-middle text-gray-400"
                            :component="$icon"
                    />
                @endif

                {{ $title }}
            @endif
        </{{ $headingSize }}>
        {{-- format-ignore-end --}}

            {!! app(RenderHookRegistry::class)->renderAll(RenderHookLocation::BeforeTitle, $item ?? null) !!}

            @if ($parentPageUrl && $parentLabel)
                <a
                    class="hover:text-primary focus:text-primary line-clamp-1 inline-block py-1 text-xs font-medium break-words text-gray-800"
                    href="{{ $parentPageUrl->full_url }}"
                    title="{{ htmlspecialchars(strip_tags($parentLabel)) }}"
                    @wireNavigate
                >
                    &raquo;
                    {{ $parentLabel }}
                </a>
            @endif

            @if ($summary)
                <p class="mt-3 line-clamp-5 leading-7 text-slate-600">
                    {{ $summary }}
                </p>
            @endif
        </div>

        {!! app(RenderHookRegistry::class)->renderAll(RenderHookLocation::AfterTitle, $item ?? null) !!}

        @if (($linkText && $url) || ($publishDate && $publishDatePosition === 'bottom'))
            <div
                class="mt-3 flex flex-wrap items-center justify-between gap-1 gap-x-4"
            >
                @if ($linkText && $url)
                    <a
                        class="inline-flex items-center gap-2 font-semibold text-blue-600 underline-offset-2 transition hover:text-blue-700 hover:underline focus:text-blue-700 focus:underline"
                        href="{{ $url }}"
                        @wireNavigate
                    >
                        {{ $linkText }}
                        @svg('heroicon-o-arrow-right', 'h-4 w-4')
                    </a>
                @endif

                @if ($publishDate && $publishDatePosition === 'bottom')
                    <time
                        class="ml-auto inline-flex items-center gap-1 text-xs font-medium text-gray-600 uppercase"
                        title="{{ __('capell-frontend::generic.publish_date', ['date' => $publishDate->format(config('capell-frontend.date_format'))]) }}"
                        datetime="{{ $publishDate->toW3cString() }}"
                    >
                        @if ($author)
                            <span class="font-semibold">
                                {{ $author->name }}
                            </span>
                            ,
                        @endif

                        {{ $publishDate->format(config('capell-frontend.date_format')) }}
                    </time>
                @endif
            </div>
        @endif

        {!! app(RenderHookRegistry::class)->renderAll(RenderHookLocation::Footer, $item ?? null) !!}
    </div>
</div>
