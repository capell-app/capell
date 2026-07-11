<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

final class AdminPanelEntrypoint
{
    public static function domain(): ?string
    {
        $domain = config('capell-admin.domain');

        if (! is_string($domain)) {
            return null;
        }

        $domain = trim($domain);

        return $domain === '' ? null : $domain;
    }

    public static function path(): string
    {
        $path = config('capell-admin.path', 'admin');

        if (! is_string($path)) {
            return 'admin';
        }

        $path = trim($path, '/');

        if ($path === '' && self::domain() !== null) {
            return '';
        }

        return $path === '' ? 'admin' : $path;
    }
}
