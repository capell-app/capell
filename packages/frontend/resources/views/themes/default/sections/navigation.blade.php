<nav
    class="default-theme-nav"
    aria-label="{{ __('capell-frontend::generic.main_navigation') }}"
>
    <div class="default-theme-container default-theme-nav__inner">
        <a
            href="/"
            class="default-theme-brand"
        >
            <span class="default-theme-brand__mark">
                {{ collect(explode(' ', trim($section->brandName)))->filter()->map(fn (string $word): string => mb_substr($word, 0, 1))->take(2)->implode('') ?: 'C' }}
            </span>
            <span>{{ $section->brandName }}</span>
        </a>

        @if ($section->items !== [])
            <div class="default-theme-nav__links">
                @foreach ($section->items as $item)
                    @if (! empty($item['url']) && ! empty($item['label']))
                        <a href="{{ $item['url'] }}">
                            {{ $item['label'] }}
                        </a>
                    @endif
                @endforeach
            </div>

            <details class="default-theme-nav__menu">
                <summary>{{ __('capell-frontend::generic.menu') }}</summary>
                <div>
                    @foreach ($section->items as $item)
                        @if (! empty($item['url']) && ! empty($item['label']))
                            <a href="{{ $item['url'] }}">
                                {{ $item['label'] }}
                            </a>
                        @endif
                    @endforeach
                </div>
            </details>
        @endif

        @if ($section->ctaLabel && $section->ctaUrl)
            <a
                href="{{ $section->ctaUrl }}"
                class="default-theme-button default-theme-button--primary default-theme-nav__cta"
            >
                {{ $section->ctaLabel }}
            </a>
        @endif
    </div>
</nav>
