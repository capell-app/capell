@php
    use Capell\Frontend\Support\Error\ErrorPageFallbackManifest;

    $host = strtolower(request()->getHost());
    $status = trim($__env->yieldContent('code'));
    $status = $status !== '' ? $status : '500';
    $manifestLogo = ErrorPageFallbackManifest::logoUrl($host);
    $manifestCopy = ErrorPageFallbackManifest::copy($host, $status);
    $showHomepageLink = request()->getPathInfo() !== '/';
    $headline = ($manifestCopy['headline'] ?? null)
        ?: trim($__env->yieldContent('headline', __('Something went wrong')));
    $description = ($manifestCopy['description'] ?? null)
        ?: trim($__env->yieldContent('description', __('Try again later.')));
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta
            name="viewport"
            content="width=device-width, initial-scale=1"
        />
        <meta
            name="robots"
            content="noindex"
        />

        <title>@yield('title')</title>

        <style>
            :root {
                color-scheme: light;
            }

            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                min-height: 100vh;
                background: #f8fafc;
                color: #0f172a;
                font-family:
                    ui-sans-serif,
                    system-ui,
                    -apple-system,
                    BlinkMacSystemFont,
                    'Segoe UI',
                    sans-serif;
            }

            main {
                display: grid;
                min-height: 100vh;
                place-items: center;
                padding: 2rem;
            }

            .error-page {
                display: grid;
                gap: 1.25rem;
                justify-items: center;
                max-width: 32rem;
                text-align: center;
            }

            .error-page__logo {
                width: min(13rem, 60vw);
                height: auto;
                margin-bottom: 0.5rem;
            }

            .error-page__code {
                color: #94a3b8;
                font-size: 0.875rem;
                font-weight: 600;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                margin: 0;
            }

            .error-page__headline {
                color: #0f172a;
                font-size: clamp(1.5rem, 4vw, 2rem);
                font-weight: 700;
                line-height: 1.2;
                margin: 0;
            }

            .error-page__description {
                color: #475569;
                font-size: 1.0625rem;
                line-height: 1.6;
                margin: 0;
            }

            .error-page__home {
                display: inline-flex;
                align-items: center;
                margin-top: 0.5rem;
                padding: 0.625rem 1.5rem;
                border-radius: 9999px;
                background: #001d3d;
                color: #ffffff;
                font-size: 0.9375rem;
                font-weight: 600;
                text-decoration: none;
                transition: background-color 150ms ease;
            }

            .error-page__home:hover,
            .error-page__home:focus-visible {
                background: #0f3a66;
            }
        </style>
    </head>
    <body>
        <main>
            <section
                class="error-page"
                aria-labelledby="error-headline"
            >
                {{--
                    Served as a static, cacheable file rather than an inline
                    SVG so the error page stays lightweight under load.
                --}}
                <img
                    class="error-page__logo"
                    src="{{ $manifestLogo ?? asset('capell-logo.svg') }}"
                    width="240"
                    height="54"
                    alt="Capell"
                />

                <p class="error-page__code">
                    @yield('code')
                    &middot;
                    @yield('message')
                </p>

                <h1
                    id="error-headline"
                    class="error-page__headline"
                >
                    {{ $headline }}
                </h1>

                <p class="error-page__description">
                    {{ $description }}
                </p>

                @if ($showHomepageLink)
                    <a
                        class="error-page__home"
                        href="{{ url('/') }}"
                    >
                        {{ __('Back to homepage') }}
                    </a>
                @endif
            </section>
        </main>
    </body>
</html>
