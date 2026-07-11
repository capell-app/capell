<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Core\Exceptions\UrlMissingSiteDomainException;
use Capell\Core\Models\PageUrl;

final class PageUrlPresenter
{
    public static function fullUrl(?PageUrl $pageUrl): ?string
    {
        if (! $pageUrl instanceof PageUrl || ! $pageUrl->exists) {
            return null;
        }

        try {
            return $pageUrl->fullUrl();
        } catch (UrlMissingSiteDomainException) {
            return null;
        }
    }

    public static function displayUrl(?PageUrl $pageUrl): string
    {
        if (! $pageUrl instanceof PageUrl) {
            return '';
        }

        return self::fullUrl($pageUrl) ?? (string) $pageUrl->url;
    }
}
