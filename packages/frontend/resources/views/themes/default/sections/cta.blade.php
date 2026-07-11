<section class="default-theme-section default-theme-cta">
    <div class="default-theme-container default-theme-cta__inner">
        <div>
            <h2>{{ $section->heading }}</h2>

            @if ($section->summary)
                <p>{{ $section->summary }}</p>
            @endif
        </div>

        @if ($section->actions !== [])
            <div class="default-theme-actions">
                @foreach ($section->actions as $action)
                    @if (! empty($action['url']) && ! empty($action['label']))
                        <a
                            href="{{ $action['url'] }}"
                            class="default-theme-button default-theme-button--on-dark"
                        >
                            {{ $action['label'] }}
                        </a>
                    @endif
                @endforeach
            </div>
        @endif
    </div>
</section>
