<section class="default-theme-section">
    <div class="default-theme-container">
        <div class="default-theme-section-heading">
            <p class="default-theme-eyebrow">
                {{ __('capell-frontend::generic.content') }}
            </p>
            <h2>{{ $section->heading }}</h2>

            @if ($section->summary)
                <p>{{ $section->summary }}</p>
            @endif
        </div>

        <div class="default-theme-list">
            @forelse ($section->items as $item)
                <article class="default-theme-list-item">
                    @if (! empty($item['image']) || ! empty($item['imageUrl']))
                        <img
                            src="{{ $item['image'] ?? $item['imageUrl'] }}"
                            alt=""
                            loading="lazy"
                            decoding="async"
                        />
                    @endif

                    <div>
                        @if (! empty($item['type']))
                            <p class="default-theme-card__meta">
                                {{ $item['type'] }}
                            </p>
                        @endif

                        <h3>
                            @if (! empty($item['url']))
                                <a href="{{ $item['url'] }}">
                                    {{ $item['title'] ?? $item['name'] ?? '' }}
                                </a>
                            @else
                                {{ $item['title'] ?? $item['name'] ?? '' }}
                            @endif
                        </h3>

                        @if (! empty($item['summary']) || ! empty($item['description']))
                            <p>
                                {{ $item['summary'] ?? $item['description'] }}
                            </p>
                        @endif
                    </div>
                </article>
            @empty
                <p class="default-theme-empty">
                    {{ __('capell-frontend::generic.no_results') }}
                </p>
            @endforelse
        </div>
    </div>
</section>
