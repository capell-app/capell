{{--
    Capell admin draft preview shell.
    
    Renders the page record's translations within an admin-styled wrapper so
    editors can see what they've authored without making the page publicly
    visible. The signed-URL gate keeps this out of public reach.
    
    Deep frontend integration (matching the theme + layout) is a follow-up;
    the structural pieces (token, route, controller, banner) ship now.
--}}
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8" />
        <meta
            name="viewport"
            content="width=device-width, initial-scale=1.0"
        />
        <meta
            name="robots"
            content="noindex, nofollow"
        />
        <title>Draft preview — {{ $page->name }}</title>
        <style>
            :root {
                color-scheme: light dark;
            }
            body {
                margin: 0;
                font-family:
                    ui-sans-serif,
                    system-ui,
                    -apple-system,
                    BlinkMacSystemFont,
                    'Segoe UI',
                    sans-serif;
                background: #fafafa;
                color: #18181b;
            }
            .preview-banner {
                position: sticky;
                top: 0;
                background: #fef3c7;
                border-bottom: 1px solid #f59e0b;
                color: #92400e;
                padding: 0.625rem 1rem;
                font-size: 0.875rem;
                display: flex;
                gap: 0.75rem;
                align-items: center;
                justify-content: space-between;
                z-index: 1000;
            }
            .preview-banner strong {
                color: #78350f;
            }
            .preview-banner .close {
                background: transparent;
                border: 1px solid #f59e0b;
                border-radius: 0.375rem;
                padding: 0.25rem 0.625rem;
                font-size: 0.8125rem;
                color: #78350f;
                text-decoration: none;
                cursor: pointer;
            }
            .preview-content {
                max-width: 720px;
                margin: 2.5rem auto;
                padding: 0 1.5rem;
            }
            .preview-meta {
                color: #71717a;
                font-size: 0.875rem;
                margin: 0 0 1rem;
            }
            h1 {
                font-size: 2.25rem;
                margin: 0 0 1rem;
                line-height: 1.15;
            }
            .translation {
                margin: 2rem 0;
                padding: 1.25rem 1.5rem;
                background: #fff;
                border: 1px solid #e4e4e7;
                border-radius: 0.75rem;
            }
            .translation-meta {
                font-size: 0.75rem;
                color: #71717a;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                margin: 0 0 0.5rem;
            }
            .translation h2 {
                margin: 0 0 0.75rem;
                font-size: 1.5rem;
            }
            .translation .content {
                line-height: 1.6;
            }
            @media (prefers-color-scheme: dark) {
                body {
                    background: #18181b;
                    color: #f4f4f5;
                }
                .translation {
                    background: #27272a;
                    border-color: #3f3f46;
                }
                .preview-meta,
                .translation-meta {
                    color: #a1a1aa;
                }
            }
        </style>
    </head>
    <body>
        <div
            class="preview-banner"
            role="status"
            aria-live="polite"
        >
            <span>
                <strong>Draft preview</strong>
                — this view is gated by a signed URL and is not visible to the
                public.
            </span>
            <a
                class="close"
                href="javascript:window.close()"
            >
                Close preview
            </a>
        </div>
        <main class="preview-content">
            <p class="preview-meta">
                Page #{{ $page->id }} · {{ $page->site?->name ?? '—' }} ·
                {{ $page->type?->name ?? '—' }}
            </p>
            <h1>{{ $page->name }}</h1>
            @forelse ($page->translations as $translation)
                <article class="translation">
                    <p class="translation-meta">
                        {{ $translation->language?->name ?? 'Translation' }}
                    </p>
                    <h2>{{ $translation->title }}</h2>
                    <div class="content">
                        {!! Str::of((string) $translation->content)->markdown() ?? e((string) $translation->content) !!}
                    </div>
                </article>
            @empty
                <p>This page has no translations yet.</p>
            @endforelse
        </main>
    </body>
</html>
