@if ($renderData->url)
    <a
        href="{{ $renderData->url }}"
        title="{{ htmlspecialchars(strip_tags($renderData->title)) }}"
        {{
            $attributes->class([
                'capell-component capell-page-neighbor-link neighbor-link hover:text-primary focus:text-primary group flex max-w-[50%] items-center gap-x-4 gap-y-3 py-6 md:py-3 lg:gap-x-4',
                'neighbor-link-next ml-auto justify-end text-right md:pl-10' => $renderData->direction === 'next',
                'neighbor-link-prev md:pr-10' => $renderData->direction === 'previous',
            ])
        }}
        @wireNavigate
    >
        @if ($renderData->direction === 'previous')
            @svg('heroicon-s-chevron-left', 'group-hover:text-primary group-focus:text-primary relative h-8 w-8 shrink-0 opacity-25 group-hover:opacity-100 group-focus:opacity-100')
        @endif

        @if ($renderData->direction === 'previous' && $renderData->renderedImage)
            {!! $renderData->renderedImage !!}
        @endif

        <span class="flex flex-col">
            <span class="mb-1 text-xs font-bold text-gray-600 uppercase">
                {{ $renderData->direction === 'previous' ? __('Previous') : __('Next') }}
            </span>
            <span
                class="line-clamp-1 text-base font-medium text-current group-hover:underline group-focus:underline"
            >
                {{ strip_tags($renderData->label) }}
            </span>
            @if ($renderData->summary)
                <span
                    class="mt-0.5 line-clamp-2 text-sm break-words text-gray-500"
                >
                    {{ strip_tags($renderData->summary) }}
                </span>
            @endif
        </span>

        @if ($renderData->direction === 'next' && $renderData->renderedImage)
            {!! $renderData->renderedImage !!}
        @endif

        @if ($renderData->direction === 'next')
            @svg('heroicon-s-chevron-right', 'group-hover:text-primary group-focus:text-primary relative h-8 w-8 shrink-0 opacity-25 group-hover:opacity-100 group-focus:opacity-100')
        @endif
    </a>
@endif
