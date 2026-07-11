<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Context;

use Capell\Core\Models\Page;
use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Contracts\UrlSignatureVerifierInterface;
use Capell\Frontend\Facades\Frontend;
use Capell\Frontend\Support\CapellFrontendContext;
use Capell\Frontend\Support\Loader\PageCachePolicy;

final class FrontendContext
{
    /**
     * Resolve the active frontend context (the CapellFrontendContext singleton).
     */
    public static function current(): CapellFrontendContext
    {
        return resolve(CapellFrontendContext::class);
    }

    /**
     * Determine if the current page should be cached.
     */
    public static function shouldCachePage(): bool
    {
        $page = Frontend::page();

        return PageCachePolicy::shouldCache($page);
    }

    public static function isErrorPage(): bool
    {
        $page = Frontend::page();

        if ($page instanceof Page) {
            return $page->isErrorPage();
        }

        return false;
    }

    /**
     * Validate URL signature against the site domain.
     */
    public static function isValidSignature(SiteDomain $domain, string $url, ?string $signature): bool
    {
        if (in_array($signature, [null, '', '0'], true)) {
            return false;
        }

        $verifier = resolve(UrlSignatureVerifierInterface::class);

        return $verifier->checkSignedUrl($domain->full_url . $url, $signature);
    }
}
