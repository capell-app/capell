<section class="default-theme-section default-theme-proof">
    <div class="default-theme-container default-theme-proof__grid">
        <div>
            <p class="default-theme-eyebrow">
                {{ __('capell-frontend::generic.proof') }}
            </p>
            <h2>{{ $section->heading }}</h2>

            @if ($section->summary)
                <p>{{ $section->summary }}</p>
            @endif
        </div>

        <div class="default-theme-proof__items">
            @foreach ($section->items as $item)
                <figure>
                    @if (! empty($item['metric']))
                        <p class="default-theme-card__meta">
                            {{ $item['metric'] }}
                        </p>
                    @endif

                    <blockquote>
                        {{ $item['quote'] ?? $item['summary'] ?? '' }}
                    </blockquote>

                    @if (! empty($item['title']) || ! empty($item['name']) || ! empty($item['logo']))
                        <figcaption>
                            {{ $item['title'] ?? $item['name'] ?? $item['logo'] }}
                        </figcaption>
                    @endif
                </figure>
            @endforeach
        </div>
    </div>
</section>
