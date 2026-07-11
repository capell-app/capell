<li>
    <a
        class="sitemap-link flex text-sm leading-tight"
        href="{{ $sitemapPage->editUrl ?? '#' }}"
    >
        @if (isset($sitemapPage->icon))
            <x-dynamic-component
                class="mr-1 inline-block h-4 w-4 shrink-0 stroke-current"
                :component="$sitemapPage->icon"
            />
        @endif

        <span class="inline-block">
            {{ $sitemapPage->label }}
        </span>
    </a>

    <a
        class="sitemap-icon"
        href="{{ $sitemapPage->url }}"
        target="_blank"
    >
        @svg('heroicon-o-arrow-top-right-on-square', 'inline-block h-4 w-4 stroke-current')
    </a>

    @if ($sitemapPage->children?->isNotEmpty())
        <ul>
            @foreach ($sitemapPage->children as $child_page)
                @include('capell-admin::filament.pages.sitemap-page', ['sitemapPage' => $child_page])
            @endforeach
        </ul>
    @endif
</li>
