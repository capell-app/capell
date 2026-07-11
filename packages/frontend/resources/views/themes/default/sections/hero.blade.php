<section class="default-theme-section default-theme-hero">
    <div class="default-theme-container default-theme-hero__grid">
        <div class="default-theme-hero__copy">
            @if ($section->eyebrow)
                <p class="default-theme-eyebrow">{{ $section->eyebrow }}</p>
            @endif

            <h1>{{ $section->heading }}</h1>

            @if ($section->summary)
                <p class="default-theme-lede">{{ $section->summary }}</p>
            @endif

            @if ($section->actions !== [])
                <div class="default-theme-actions">
                    @foreach ($section->actions as $action)
                        @if (! empty($action['url']) && ! empty($action['label']))
                            <a
                                href="{{ $action['url'] }}"
                                @class([
                                    'default-theme-button',
                                    'default-theme-button--secondary' => ($action['style'] ?? 'primary') === 'secondary',
                                    'default-theme-button--primary' => ($action['style'] ?? 'primary') !== 'secondary',
                                ])
                            >
                                {{ $action['label'] }}
                            </a>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>

        @if ($section->mediaUrl)
            <figure class="default-theme-media-card">
                <img
                    src="{{ $section->mediaUrl }}"
                    alt="{{ $section->mediaAlt ?? '' }}"
                    width="1200"
                    height="760"
                    loading="eager"
                    decoding="async"
                    fetchpriority="high"
                />
            </figure>
        @endif
    </div>
</section>
