@php
    use Capell\Core\Models\Language;
    use Capell\Core\Models\Site;
    use Capell\Core\Models\SiteDomain;
    use Capell\Frontend\Contracts\FrontendSettingsReaderInterface;
    use Capell\Frontend\Enums\VisitorLanguageDetection;
    use Capell\Frontend\Facades\Frontend;
    use Capell\Frontend\Support\Locale\VisitorLanguageCookie;

    /**
     * Visitor language banner.
     *
     * CACHE CONTRACT: everything below is a pure function of host + path. Every
     * site language is rendered into the page, every time; the client picks one.
     * Nothing here may read a request header, or the shared host+path HTML cache
     * entry would start serving one visitor's language to everyone.
     *
     * That is also why the copy is rendered as a full cross product of
     * (UI language x suggested language) rather than interpolated in JavaScript:
     * the placeholder is a language NAME, which has to be translated too, and
     * the set of pairs is small (site languages, typically two to five).
     */
    $mode = app(FrontendSettingsReaderInterface::class)->visitorLanguageDetection();

    $site = Frontend::site();
    $pageLanguage = Frontend::language();

    $languages = collect();

    if ($mode !== VisitorLanguageDetection::Off && $site instanceof Site && $pageLanguage instanceof Language) {
        $languages = collect($site->siteDomains ?? [])
            ->filter(fn (SiteDomain $domain): bool => $domain->status && $domain->language instanceof Language)
            ->map(fn (SiteDomain $domain): Language => $domain->language)
            ->unique('id')
            ->values();
    }

    $tagOf = static fn (Language $language): string => strtolower(str_replace(
        '_',
        '-',
        filled($language->locale) ? (string) $language->locale : (string) $language->code,
    ));

    // A single language means there is nothing to suggest and nothing to revert.
    $render = $languages->count() > 1;
    $pageTag = $pageLanguage instanceof Language ? $tagOf($pageLanguage) : '';
@endphp

@if ($render)
    <div
        id="capell-language-banner"
        class="capell-language-banner"
        role="region"
        aria-label="{{ $mode === VisitorLanguageDetection::Redirect ? __('capell-frontend::languages.redirected_banner_label') : __('capell-frontend::languages.suggestion_banner_label') }}"
        aria-live="polite"
        data-capell-language-banner="{{ $mode->value }}"
        data-capell-page-language="{{ $pageTag }}"
        data-capell-cookie="{{ VisitorLanguageCookie::NAME }}"
        data-capell-origin-cookie="{{ VisitorLanguageCookie::ORIGIN_NAME }}"
        hidden
    >
        {{-- First in tab order on purpose: the way out comes before the offer. --}}
        <button
            type="button"
            class="capell-language-banner__dismiss"
            data-capell-language-dismiss
            aria-label="{{ __('capell-frontend::languages.suggestion_dismiss') }}"
        >
            <span aria-hidden="true">&times;</span>
        </button>

        <div class="capell-language-banner__body">
            @foreach ($languages as $uiLanguage)
                @php $uiTag = $tagOf($uiLanguage); @endphp
                <div
                    class="capell-language-banner__variant"
                    data-capell-language-variant="{{ $uiTag }}"
                    lang="{{ $uiTag }}"
                    dir="{{ Language::directionForCode($uiTag) }}"
                    hidden
                >
                    @foreach ($languages as $targetLanguage)
                        @php $targetTag = $tagOf($targetLanguage); @endphp

                        <p
                            class="capell-language-banner__message"
                            data-capell-language-suggest="{{ $targetTag }}"
                            hidden
                        >
                            <span>
                                {{ trans('capell-frontend::languages.suggestion_message', ['language' => $targetLanguage->name], $uiTag) }}
                            </span>
                            <a
                                class="capell-language-banner__action"
                                data-capell-language-switch
                                lang="{{ $targetTag }}"
                                href="#"
                                rel="alternate nofollow"
                                hreflang="{{ $targetTag }}"
                            >{{ trans('capell-frontend::languages.suggestion_switch', ['language' => $targetLanguage->name], $uiTag) }}</a>
                        </p>

                        <p
                            class="capell-language-banner__message"
                            data-capell-language-reverted="{{ $targetTag }}"
                            hidden
                        >
                            {{-- ":language" here is the language of THIS page (where the
                                 visitor was sent), while the link offers the language they
                                 came FROM. --}}
                            <span>
                                {{ trans('capell-frontend::languages.redirected_message', ['language' => $pageLanguage->name], $uiTag) }}
                            </span>
                            <a
                                class="capell-language-banner__action"
                                data-capell-language-revert
                                lang="{{ $targetTag }}"
                                href="#"
                                rel="alternate nofollow"
                                hreflang="{{ $targetTag }}"
                            >{{ trans('capell-frontend::languages.redirected_revert', ['language' => $targetLanguage->name], $uiTag) }}</a>
                        </p>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>

    <style>
        /* Fixed/overlay so the banner cannot shift the cached layout: a page
           served from the HTML cache paints identically whether or not the
           script later reveals this. */
        .capell-language-banner {
            position: fixed;
            z-index: 2147483000;
            right: 1rem;
            bottom: 1rem;
            left: 1rem;
            display: flex;
            gap: 0.75rem;
            align-items: flex-start;
            max-width: 32rem;
            margin-inline: auto;
            border: 1px solid rgba(0, 0, 0, 0.12);
            border-radius: 0.5rem;
            background: Canvas;
            box-shadow: 0 0.5rem 1.5rem rgba(0, 0, 0, 0.18);
            color: CanvasText;
            padding: 0.875rem 1rem;
            font-size: 0.9375rem;
            line-height: 1.4;
        }

        .capell-language-banner[hidden] {
            display: none;
        }

        .capell-language-banner__variant[hidden],
        .capell-language-banner__message[hidden] {
            display: none;
        }

        .capell-language-banner__body {
            flex: 1 1 auto;
        }

        .capell-language-banner__message {
            margin: 0;
        }

        .capell-language-banner__dismiss {
            order: 2;
            flex: 0 0 auto;
            border: 0;
            border-radius: 0.25rem;
            background: transparent;
            color: inherit;
            cursor: pointer;
            padding: 0.125rem 0.375rem;
            font-size: 1.25rem;
            line-height: 1;
        }

        .capell-language-banner__action {
            color: inherit;
            text-decoration: underline;
        }

        @media (prefers-reduced-motion: no-preference) {
            .capell-language-banner {
                animation: capell-language-banner-in 180ms ease-out;
            }

            @keyframes capell-language-banner-in {
                from {
                    opacity: 0;
                    transform: translateY(0.5rem);
                }
            }
        }
    </style>

    <script>
        (function () {
            var banner = document.getElementById('capell-language-banner');

            if (!banner) {
                return;
            }

            var DISMISS_KEY = 'capell-language-banner-dismissed';
            var mode = banner.getAttribute('data-capell-language-banner');
            var pageTag = (banner.getAttribute('data-capell-page-language') || '').toLowerCase();

            function base(tag) {
                return String(tag || '').toLowerCase().replace('_', '-').split('-')[0];
            }

            function readCookie(name) {
                var parts = document.cookie ? document.cookie.split(';') : [];

                for (var i = 0; i < parts.length; i++) {
                    var pair = parts[i].trim();

                    if (pair.indexOf(name + '=') === 0) {
                        return decodeURIComponent(pair.slice(name.length + 1));
                    }
                }

                return null;
            }

            function clearCookie(name) {
                document.cookie = name + '=; Max-Age=0; Path=/; SameSite=Lax';
            }

            function stored(key) {
                try {
                    return window.localStorage.getItem(key);
                } catch (error) {
                    return null;
                }
            }

            function store(key, value) {
                try {
                    window.localStorage.setItem(key, value);
                } catch (error) {
                    /* Private browsing. Dismissal degrades to per-page-view. */
                }
            }

            if (stored(DISMISS_KEY)) {
                return;
            }

            // Pick the UI variant the visitor is most likely to read, falling
            // back to the language of the page itself.
            function pickVariant() {
                var variants = banner.querySelectorAll('[data-capell-language-variant]');
                var preferred = navigator.languages && navigator.languages.length
                    ? navigator.languages
                    : [navigator.language || pageTag];

                for (var p = 0; p < preferred.length; p++) {
                    for (var v = 0; v < variants.length; v++) {
                        if (base(variants[v].getAttribute('data-capell-language-variant')) === base(preferred[p])) {
                            return variants[v];
                        }
                    }
                }

                return banner.querySelector('[data-capell-language-variant="' + pageTag + '"]') || variants[0] || null;
            }

            function reveal(variant, message, href) {
                if (!variant || !message) {
                    return;
                }

                var link = message.querySelector('a');

                if (!link || !href) {
                    return;
                }

                link.setAttribute('href', href);

                // Acting on the banner is a preference signal, exactly like using
                // the language switcher: record it before navigating so the
                // destination never re-evaluates the visitor.
                var chosen = link.getAttribute('hreflang');

                if (chosen) {
                    link.addEventListener('click', function () {
                        document.cookie = banner.getAttribute('data-capell-cookie')
                            + '=' + encodeURIComponent(chosen)
                            + '; Max-Age=31536000; Path=/; SameSite=Lax'
                            + (location.protocol === 'https:' ? '; Secure' : '');
                    });
                }

                variant.hidden = false;
                message.hidden = false;
                banner.hidden = false;
            }

            var dismiss = banner.querySelector('[data-capell-language-dismiss]');

            if (dismiss) {
                dismiss.addEventListener('click', function () {
                    store(DISMISS_KEY, '1');
                    banner.hidden = true;
                });
            }

            var variant = pickVariant();

            // 1. Post-redirect notice. The origin cookie is written by the
            //    redirect itself and cleared here, so the notice is one-time.
            //    A wrong guess must always be recoverable.
            var originCookie = banner.getAttribute('data-capell-origin-cookie');
            var origin = readCookie(originCookie);

            if (origin) {
                clearCookie(originCookie);

                var separator = origin.indexOf(' ');

                if (separator > 0) {
                    var originTag = origin.slice(0, separator).toLowerCase();
                    var originUrl = origin.slice(separator + 1);
                    var revert = variant && variant.querySelector('[data-capell-language-reverted="' + originTag + '"]');

                    if (!revert && variant) {
                        // Fall back to a base-language match (en-GB vs en-US).
                        var candidates = variant.querySelectorAll('[data-capell-language-reverted]');

                        for (var c = 0; c < candidates.length; c++) {
                            if (base(candidates[c].getAttribute('data-capell-language-reverted')) === base(originTag)) {
                                revert = candidates[c];
                                break;
                            }
                        }
                    }

                    reveal(variant, revert, originUrl);

                    return;
                }
            }

            // 2. Suggestion. Only in banner mode, and never once the visitor has
            //    expressed a preference.
            if (mode !== 'banner' || readCookie(banner.getAttribute('data-capell-cookie'))) {
                return;
            }

            var preferences = navigator.languages && navigator.languages.length
                ? navigator.languages
                : [navigator.language];

            // The visitor reads this page's language. Say nothing.
            for (var i = 0; i < preferences.length; i++) {
                if (base(preferences[i]) === base(pageTag)) {
                    return;
                }
            }

            // Reuse the hreflang cluster already in <head> rather than emitting
            // the same URLs a second time.
            var alternates = document.querySelectorAll('link[rel~="alternate"][hreflang]');
            var target = null;

            outer: for (var pref = 0; pref < preferences.length; pref++) {
                for (var a = 0; a < alternates.length; a++) {
                    var hreflang = (alternates[a].getAttribute('hreflang') || '').toLowerCase();

                    if (hreflang === 'x-default' || base(hreflang) === base(pageTag)) {
                        continue;
                    }

                    if (base(hreflang) === base(preferences[pref])) {
                        target = { tag: hreflang, href: alternates[a].getAttribute('href') };
                        break outer;
                    }
                }
            }

            if (!target || !target.href || !variant) {
                return;
            }

            var suggestion = variant.querySelector('[data-capell-language-suggest="' + target.tag + '"]');

            if (!suggestion) {
                var options = variant.querySelectorAll('[data-capell-language-suggest]');

                for (var o = 0; o < options.length; o++) {
                    if (base(options[o].getAttribute('data-capell-language-suggest')) === base(target.tag)) {
                        suggestion = options[o];
                        break;
                    }
                }
            }

            reveal(variant, suggestion, target.href);
        })();
    </script>
@endif
