<div>
    @foreach ($pages as $page)
        <article id="page-{{ $page['slug'] }}">
            <h2>{{ $page['title'] }}</h2>
            @foreach ($page['sections'] as $section)
                <section>
                    @if ($section['title'])
                        <h3>{{ $section['title'] }}</h3>
                    @endif
                    {!! $section['html'] !!}
                </section>
            @endforeach
        </article>
    @endforeach
</div>
