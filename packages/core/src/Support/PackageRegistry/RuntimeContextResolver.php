<?php

declare(strict_types=1);

namespace Capell\Core\Support\PackageRegistry;

use Capell\Core\Enums\RuntimeContextEnum;
use Illuminate\Support\Str;

final class RuntimeContextResolver
{
    public function resolve(): RuntimeContextEnum
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return RuntimeContextEnum::Console;
        }

        return $this->resolveFromPath(ltrim(request()->path(), '/'), request()->getHost());
    }

    public function resolveFromPath(string $path, ?string $host = null): RuntimeContextEnum
    {
        $adminDomain = $this->adminDomain();
        $adminPath = $this->adminPath($adminDomain);

        if ($this->isAdminPath($path, $host, $adminDomain, $adminPath)) {
            return RuntimeContextEnum::Admin;
        }

        if ($this->matchesAnyPath($path, config('capell.runtime.auth_paths', []))) {
            return RuntimeContextEnum::Auth;
        }

        return RuntimeContextEnum::Frontend;
    }

    private function isAdminPath(string $path, ?string $host, ?string $adminDomain, string $adminPath): bool
    {
        if ($adminDomain !== null && $host !== null && ! hash_equals($adminDomain, $host)) {
            return false;
        }

        if ($adminDomain !== null && $adminPath === '') {
            return $host === null ? false : hash_equals($adminDomain, $host);
        }

        return $adminPath !== '' && ($path === $adminPath || str_starts_with($path, $adminPath . '/'));
    }

    private function adminDomain(): ?string
    {
        $domain = config('capell-admin.domain');

        if (! is_string($domain)) {
            return null;
        }

        $domain = trim($domain);

        return $domain === '' ? null : $domain;
    }

    private function adminPath(?string $adminDomain): string
    {
        $path = config('capell-admin.path', 'admin');

        if (! is_string($path)) {
            return 'admin';
        }

        $path = trim($path, '/');

        if ($path === '' && $adminDomain !== null) {
            return '';
        }

        return $path === '' ? 'admin' : $path;
    }

    private function matchesAnyPath(string $path, mixed $patterns): bool
    {
        if (! is_array($patterns)) {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (! is_string($pattern)) {
                continue;
            }

            if ($pattern === '') {
                continue;
            }

            if (Str::is($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
