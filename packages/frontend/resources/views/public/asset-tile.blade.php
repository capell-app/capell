<div
    {{
    $attributes->class([
        'capell-component capell-asset-tile asset-item asset-tile group @container/item overflow-hidden border border-slate-200 bg-white shadow-sm ring-1 ring-slate-950/5 transition duration-200 hover:border-slate-300 hover:shadow-md',
        'grid grid-cols-12' => $renderData->renderedImage !== null && ! $renderData->imageOnTop,
        'flex flex-col' => $renderData->imageOnTop || $renderData->renderedImage === null,
        'cursor-pointer' => $renderData->url,
        'rounded-lg' => $renderData->roundedImages,
    ])
}}
>
    @if ($renderData->renderedImage !== null)
        @php
            $imageWrapClass = match (true) {
                $renderData->imageOnTop => 'w-full',
                $renderData->imageOnRight => 'order-last col-span-4',
                default => 'col-span-4',
            };
        @endphp

        <div class="{{ $imageWrapClass }}">
            @if ($renderData->url)
                <a
                    href="{{ $renderData->url }}"
                    @wireNavigate
                >
                    {!! $renderData->renderedImage !!}
                </a>
            @else
                {!! $renderData->renderedImage !!}
            @endif
        </div>
    @endif

    <div
        @class([
        'flex flex-1 flex-col p-6',
        'lg:px-8 lg:py-6' => $renderData->size !== 'sm',
        'col-span-8' => $renderData->renderedImage !== null && ! $renderData->imageOnTop && ! $renderData->imageOnRight,
        'order-first col-span-8' => $renderData->renderedImage !== null && $renderData->imageOnRight,
    ])
    >
        @if ($renderData->dateTime && $renderData->datePosition === 'top')
            <time
                class="publish-date mb-2 inline-flex items-center text-xs leading-tight tracking-[0.02em] text-gray-600"
                title="{{ __('capell-frontend::generic.publish_date', ['date' => $renderData->date]) }}"
                datetime="{{ $renderData->dateTime }}"
            >
                @svg('heroicon-o-calendar', 'mr-1 inline-block h-4 w-4 align-middle text-gray-400')
                {{ $renderData->date }}
                @if ($renderData->authorName !== null)
                    <span class="ml-1">
                        {{ __('capell-frontend::generic.editor_by', ['name' => $renderData->authorName]) }}
                    </span>
                @endif
            </time>
        @endif

        {!! $renderData->beforeContent !!}

        <div
            @class([
            'max-w-none break-words [&>:first-child]:mt-0 [&>:last-child]:mb-0',
        ])
        >
            {{-- format-ignore-start --}}
            <{{ $renderData->headingSize }}
            @class([
                'font-heading',
                'mb-0' => ! $renderData->hasParent &&
                ! $renderData->summary,
                'text-md lg:text-lg' => $renderData->size === 'sm',
                'text-xl xl:text-2xl' => $renderData->size === 'lg',
                'text-balance' => $renderData->titleBalance,
            ])
            >
            @if ($renderData->url)
                <a
                        class="hover:text-primary focus:text-primary inline-block break-words font-semibold text-slate-950 no-underline transition"
                        href="{{ $renderData->url }}"
                        title="{{ htmlspecialchars(strip_tags($renderData->title)) }}"
                        @wireNavigate
                >
                    @if ($renderData->icon)
                        <x-dynamic-component
                                class="-ml-1 mr-0.5 inline-block h-4 w-4 shrink-0 align-middle text-gray-400"
                                :component="$renderData->icon"
                        />
                    @endif

                    {{ $renderData->title }}
                </a>
            @else
                @if ($renderData->icon)
                    <x-dynamic-component
                            class="-ml-1 mr-0.5 inline-block h-4 w-4 shrink-0 align-middle text-gray-400"
                            :component="$renderData->icon"
                    />
                @endif

                {{ $renderData->title }}
            @endif
        </{{ $renderData->headingSize }}>
        {{-- format-ignore-end --}}

            {!! $renderData->beforeTitle !!}

            @if ($renderData->parentUrl && $renderData->parentLabel)
                <a
                    class="hover:text-primary focus:text-primary line-clamp-1 inline-block py-1 text-xs font-medium break-words text-gray-800"
                    href="{{ $renderData->parentUrl }}"
                    title="{{ htmlspecialchars(strip_tags($renderData->parentLabel)) }}"
                    @wireNavigate
                >
                    &raquo; {{ $renderData->parentLabel }}
                </a>
            @endif

            @if ($renderData->summary)
                <p class="mt-3 line-clamp-5 leading-7 text-slate-600">{{ $renderData->summary }}</p>
            @endif
        </div>

        {!! $renderData->afterTitle !!}

        @if (($renderData->linkText && $renderData->url) || ($renderData->dateTime && $renderData->datePosition === 'bottom'))
            <div
                class="mt-3 flex flex-wrap items-center justify-between gap-1 gap-x-4"
            >
                @if ($renderData->linkText && $renderData->url)
                    <a
                        class="inline-flex items-center gap-2 font-semibold text-blue-600 underline-offset-2 transition hover:text-blue-700 hover:underline focus:text-blue-700 focus:underline"
                        href="{{ $renderData->url }}"
                        @wireNavigate
                    >
                        {{ $renderData->linkText }}
                        @svg('heroicon-o-arrow-right', 'h-4 w-4')
                    </a>
                @endif

                @if ($renderData->dateTime && $renderData->datePosition === 'bottom')
                    <time
                        class="ml-auto inline-flex items-center gap-1 text-xs font-medium text-gray-600 uppercase"
                        title="{{ __('capell-frontend::generic.publish_date', ['date' => $renderData->date]) }}"
                        datetime="{{ $renderData->dateTime }}"
                    >
                        @if ($renderData->authorName !== null)
                            <span class="font-semibold">
                                {{ $renderData->authorName }}
                            </span>
                            ,
                        @endif

                        {{ $renderData->date }}
                    </time>
                @endif
            </div>
        @endif

        {!! $renderData->footer !!}
    </div>
</div>
