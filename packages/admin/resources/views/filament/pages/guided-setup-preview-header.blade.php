<header>
    <h1>{{ $name }}</h1>
    @if ($tagline !== '')
        <p>{{ $tagline }}</p>
    @endif
    @if ($nativeNavigation !== null)
        {!! $nativeNavigation !!}
    @else
        @foreach ($navigations as $navigation)
            <nav
                aria-label="{{ $navigation->name ?? __('capell-admin::setup.preview_menu') }}"
            >
                <ul>
                    @foreach ($navigation->pageSlugs as $slug)
                        @foreach ($pages as $page)
                            @if ($page->slug === $slug)
                                <li>
                                    <a
                                        href="#page-{{ $page->slug }}"
                                        >{{ $page->name }}</a
                                    >
                                </li>
                            @endif
                        @endforeach
                    @endforeach
                </ul>
            </nav>
        @endforeach
    @endif
</header>
