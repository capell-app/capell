<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Locale;

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * The single "the visitor has decided" signal.
 *
 * Deliberately NOT written with the `cookie()` helper: the queued-cookie flush
 * (`AddQueuedCookiesToResponse`) lives inside the `web` group and never runs
 * when a middleware registered *before* `web` short-circuits with a redirect.
 * The cookie is therefore constructed and attached to the response directly.
 *
 * The cookie is unencrypted, and correctness does NOT depend on that being
 * arranged: {@see self::present()} and {@see self::value()} read the raw Symfony
 * cookie bag, and `DetectVisitorLanguage` runs before the `web` group, so the
 * decrypting middleware has not touched the bag yet either way. The inline
 * banner script reads `document.cookie`, which is raw by definition.
 *
 * {@see self::exemptFromEncryption()} is therefore an optimisation for code
 * DOWNSTREAM of `web` — without it Laravel's `EncryptCookies` would fail to
 * decrypt the value and null it out for the rest of the request — and is
 * deliberately best-effort: the frontend package must boot in an application
 * that has no cookie-encryption middleware at all.
 */
final class VisitorLanguageCookie
{
    public const string NAME = 'capell_lang';

    /**
     * Carries the URL the visitor was redirected AWAY from, so the destination
     * can offer a one-way ticket back.
     *
     * This has to be a cookie rather than a header or a query parameter: the
     * destination page is served from the host+path HTML cache, so its bytes
     * cannot encode anything about this particular visitor. The banner script
     * reads the cookie client-side and clears it, making the notice one-time.
     */
    public const string ORIGIN_NAME = 'capell_lang_from';

    /** One year, in minutes. A language preference does not go stale. */
    private const int LIFETIME_MINUTES = 525600;

    /** Long enough to survive the redirect hop, short enough to never linger. */
    private const int ORIGIN_LIFETIME_SECONDS = 120;

    /**
     * Best-effort registration of both cookies as never-encrypted.
     *
     * `EncryptCookies::except()` writes to a static on Laravel's base middleware
     * that every application subclass inherits, so targeting the framework class
     * covers an `App\Http\Middleware\EncryptCookies` too. It is guarded by
     * `class_exists` because a package must not hard-depend on a middleware the
     * host application may never have registered — under Testbench, or in an app
     * that dropped cookie encryption, an unguarded reference fatals the whole
     * provider boot rather than just this feature.
     */
    public static function exemptFromEncryption(): void
    {
        /** @var class-string $middleware */
        $middleware = EncryptCookies::class;

        if (! class_exists($middleware) || ! method_exists($middleware, 'except')) {
            return;
        }

        $middleware::except([self::NAME, self::ORIGIN_NAME]);
    }

    /**
     * Reads the RAW cookie bag on purpose. This is called from a middleware that
     * runs before the `web` group, so no decryption has happened, and none is
     * needed.
     */
    public function present(Request $request): bool
    {
        return $request->cookies->has(self::NAME);
    }

    public function value(Request $request): ?string
    {
        $value = $request->cookies->get(self::NAME);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function make(string $tag, bool $secure): Cookie
    {
        return Cookie::create(self::NAME)
            ->withValue($tag)
            ->withExpires(Date::now()->getTimestamp() + (self::LIFETIME_MINUTES * 60))
            ->withPath('/')
            ->withSecure($secure)
            // Readable by the banner script, so not HttpOnly.
            ->withHttpOnly(false)
            ->withSameSite(Cookie::SAMESITE_LAX)
            ->withRaw(false);
    }

    public function makeOrigin(string $url, string $tag, bool $secure): Cookie
    {
        return Cookie::create(self::ORIGIN_NAME)
            ->withValue($tag . ' ' . $url)
            ->withExpires(Date::now()->getTimestamp() + self::ORIGIN_LIFETIME_SECONDS)
            ->withPath('/')
            ->withSecure($secure)
            ->withHttpOnly(false)
            ->withSameSite(Cookie::SAMESITE_LAX)
            ->withRaw(false);
    }
}
