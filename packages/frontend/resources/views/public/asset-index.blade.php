<div
    {{
    $attributes->class([
        '@container/item capell-component capell-asset-index asset-item asset-index group w-full min-w-0 max-w-full overflow-hidden bg-white transition duration-200',
        'flex min-h-56 rounded-lg border border-slate-200 shadow-sm ring-1 ring-slate-950/5 hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-lg' => ! $renderData->isBlogArticleCard,
        'grid min-h-0 rounded-md border border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.05)] ring-1 ring-slate-950/5 hover:border-slate-300 hover:shadow-md' => $renderData->isBlogArticleCard,
    ])
        ->only(['class', 'role'])
        ->merge(['style' => $renderData->cardStyle])
}}
>
    @if ($renderData->hasImage)
        <div
            @class([
            'asset-image w-full overflow-hidden bg-slate-100',
            'aspect-[16/9]' => ! $renderData->isBlogArticleCard,
            'h-full min-h-44' => $renderData->isBlogArticleCard,
        ])
        >
            @if ($renderData->url)
                <a
                    href="{{ $renderData->url }}"
                    title="{{ htmlspecialchars(strip_tags($renderData->title)) }}"
                    @wireNavigate
                >
                    {!! $renderData->renderedImage !!}
                </a>
            @else
                {!! $renderData->renderedImage !!}
            @endif
        </div>
    @endif

    @if ($renderData->hasContent)
        <div
            @class([
            'asset-content relative flex min-w-0 grow flex-col overflow-hidden',
            'p-6' => ! $renderData->isBlogArticleCard,
            'p-5 xl:p-6' => $renderData->isBlogArticleCard,
            "before:content-['-'] before:absolute before:left-3 before:top-8 before:text-primary before:font-bold pl-8" => $renderData->hasBullet,
        ])
        >
            @if ($renderData->hasDate && $renderData->datePosition === 'top')
                <div class="mb-5">{!! $renderData->renderedDate !!}</div>
            @endif

            {{-- format-ignore-start --}}
            @if ($renderData->headingSize)<{{ $renderData->headingSize }} class="font-heading mb-0">@endif
            {{-- format-ignore-end --}}
            @if ($renderData->url)
                <a
                    href="{{ $renderData->url }}"
                    title="{{ htmlspecialchars(strip_tags($renderData->title)) }}"
                    @class([
                        'block leading-tight font-semibold break-words text-slate-950 transition group-hover:text-blue-600 focus:text-blue-600',
                        'text-balance' => $renderData->titleBalance,
                        'text-xl md:text-2xl' => ! $renderData->size,
                        'text-2xl md:text-3xl' => $renderData->size === 'lg',
                        'text-lg md:text-xl' => $renderData->size === 'md',
                        'text-base md:text-lg' => $renderData->size === 'sm',
                    ])
                    @wireNavigate
                >
                    @if ($renderData->icon)
                        <x-dynamic-component
                            class="mr-1 -ml-1 inline-block h-4 w-4 shrink-0 align-middle text-slate-400"
                            :component="$renderData->icon"
                        />
                    @endif

                    {{ $renderData->title }}
                </a>
            @else
                <span
                    @class([
                    'block leading-tight font-semibold break-words text-slate-950',
                    'text-balance' => $renderData->titleBalance,
                    'text-xl md:text-2xl' => ! $renderData->size,
                    'text-2xl md:text-3xl' => $renderData->size === 'lg',
                    'text-lg md:text-xl' => $renderData->size === 'md',
                    'text-base md:text-lg' => $renderData->size === 'sm',
                ])
                >
                    @if ($renderData->icon)
                        <x-dynamic-component
                            class="mr-1 -ml-1 inline-block h-4 w-4 shrink-0 align-middle text-slate-400"
                            :component="$renderData->icon"
                        />
                    @endif

                    {{ $renderData->title }}
                </span>
            @endif
            {{-- format-ignore-start --}}
            @if ($renderData->headingSize)</{{ $renderData->headingSize }}>@endif
            {{-- format-ignore-end --}}

            {!! $renderData->beforeTitle !!}

            @if ($renderData->count)
                <span
                    class="mt-3 inline-flex w-fit items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500"
                >
                    {{ $renderData->count }}
                </span>
            @endif

            @if ($renderData->withSummary && $renderData->summary)
                <div
                    class="[&>:first-child]:mt-0 [&>:last-child]:mb-0 mt-4 line-clamp-3 w-full max-w-none overflow-hidden text-base leading-7 break-words text-slate-600"
                >
                    <p>{{ $renderData->summary }}</p>
                </div>
            @endif

            @if ($renderData->parentUrl && $renderData->parentLabel)
                <a
                    class="mt-4 line-clamp-2 block text-xs font-semibold tracking-[0.06em] break-words text-slate-500 uppercase transition hover:text-blue-600 focus:text-blue-600"
                    href="{{ $renderData->parentUrl }}"
                    title="{{ htmlspecialchars(strip_tags($renderData->parentLabel)) }}"
                    @wireNavigate
                >
                    {{ $renderData->parentLabel }}
                </a>
            @endif

            {!! $renderData->afterTitle !!}

            <div class="mt-auto">
                @if ($renderData->hasDate && $renderData->datePosition === 'bottom')
                    <div class="pt-6">{!! $renderData->renderedDate !!}</div>
                @endif

                @if ($renderData->linkText && $renderData->url)
                    <a
                        @class([
                            'inline-flex items-center gap-2 pt-6 text-sm font-semibold text-blue-600 transition hover:text-blue-700 focus:text-blue-700',
                        ])
                        href="{{ $renderData->url }}"
                        aria-label="{{ __('capell-frontend::generic.read_more_about', ['title' => strip_tags((string) $renderData->title)]) }}"
                        @wireNavigate
                    >
                        {{ $renderData->linkText }}
                        @if (! $renderData->isBlogArticleCard)
                            @svg('heroicon-o-arrow-right', 'h-4 w-4 transition-transform group-hover:translate-x-0.5')
                        @endif
                    </a>
                @endif
            </div>
        </div>
    @endif

    {!! $renderData->footer !!}
</div>
