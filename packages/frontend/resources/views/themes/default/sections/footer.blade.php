<footer
    id="footer"
    class="default-theme-footer"
>
    <div class="default-theme-container default-theme-footer__grid">
        <div>
            <h2>{{ $section->brandName }}</h2>

            @if ($section->summary)
                <p>{{ $section->summary }}</p>
            @endif
        </div>

        @if ($section->columns !== [])
            <div class="default-theme-footer__links">
                @foreach ($section->columns as $column)
                    <div>
                        <h3>{{ $column['heading'] }}</h3>
                        <ul>
                            @foreach ($column['links'] as $link)
                                @if (! empty($link['url']) && ! empty($link['label']))
                                    <li>
                                        <a href="{{ $link['url'] }}">
                                            {{ $link['label'] }}
                                        </a>
                                    </li>
                                @endif
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</footer>
