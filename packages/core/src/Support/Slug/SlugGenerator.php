<?php

declare(strict_types=1);

namespace Capell\Core\Support\Slug;

class SlugGenerator
{
    /**
     * Build an Alpine/Filament JS snippet that slugifies one state value into another.
     *
     * NOTE (not an injection sink): `$state` and `$statePath` are developer-supplied
     * JS expression/path references (e.g. `$wire.title`, `data.slug`) wired up at form
     * build time — never end-user input. `$state` is interpolated as a JS *expression*
     * by design, so it is intentionally not quoted/escaped. Do not "harden" this by
     * escaping it; that would break every caller. If a caller ever needs to pass an
     * untrusted literal, quote it at the call site before passing it in.
     */
    public static function slugifyState(string $state, string $statePath): string
    {
        return <<<JS
            const slug = ({$state})
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .toLowerCase()
                .trim()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
            \$set('{$statePath}', slug);
        JS;
    }
}
