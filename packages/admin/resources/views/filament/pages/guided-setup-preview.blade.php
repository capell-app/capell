<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8" />
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    />
    <meta
        name="robots"
        content="noindex, nofollow"
    />
    <meta
        http-equiv="Content-Security-Policy"
        content="default-src 'none'; style-src 'unsafe-inline' http: https:; font-src http: https:; img-src data:; base-uri about:; form-action 'none'"
    />
    <base href="about:srcdoc" />
    <title>{{ $name }}</title>
    @if ($stylesheet)
        <link
            rel="stylesheet"
            href="{{ $stylesheet }}"
        />
    @endif
    <style>
            {!! $tokens !!}
            html { scroll-behavior: smooth; }
            body { margin: 0; background: var(--theme-surface); color: var(--theme-foreground); font-family: var(--theme-body-font, system-ui), sans-serif; }
            header, main { padding: 1.5rem; max-width: 64rem; margin: auto; }
            header { border-bottom: 2px solid var(--theme-primary); }
            h1, h2, h3 { font-family: var(--theme-heading-font, system-ui), sans-serif; }
            h1 { color: var(--theme-primary); }
            nav ul { display: flex; flex-wrap: wrap; list-style: none; padding: 0; gap: 1rem; }
            a { color: var(--theme-primary); }
            article { padding-block: 1rem; scroll-margin-top: 1rem; }
            article + article { border-top: 1px solid currentColor; }
            section { margin-block: 1.5rem; line-height: 1.6; }
            @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } }
        </style>
</head>
<body>
    @if ($nativePage)
        {!! $content !!}
    @else
        <main>{!! $content !!}</main>
    @endif
</body>
</html>
