<section class="default-theme-section default-theme-section--raised">
    <div class="default-theme-container">
        <div class="default-theme-section-heading">
            <p class="default-theme-eyebrow">
                {{ __('capell-frontend::generic.features') }}
            </p>
            <h2>{{ $section->heading }}</h2>

            @if ($section->summary)
                <p>{{ $section->summary }}</p>
            @endif
        </div>

        <div class="default-theme-card-grid">
            @forelse ($section->features as $feature)
                <article class="default-theme-card">
                    @if (! empty($feature['image']))
                        <img
                            src="{{ $feature['image'] }}"
                            alt=""
                            loading="lazy"
                            decoding="async"
                        />
                    @endif

                    <div>
                        @if (! empty($feature['type']))
                            <p class="default-theme-card__meta">
                                {{ $feature['type'] }}
                            </p>
                        @endif

                        <h3>{{ $feature['title'] }}</h3>

                        @if (! empty($feature['description']))
                            <p>{{ $feature['description'] }}</p>
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
