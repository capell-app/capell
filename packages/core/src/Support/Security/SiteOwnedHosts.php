<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security;

use Capell\Core\Models\SiteDomain;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Throwable;
use WeakMap;

/**
 * Hosts this installation serves pages, assets or uploads from. A sandboxed frame
 * with allow-scripts and allow-same-origin on one of them can remove its own
 * sandbox, so none of them is ever an embed host.
 */
final class SiteOwnedHosts
{
    /**
     * Site-domain answers cached for the life of each request object only, so a
     * long-lived worker never carries one request's answers into the next.
     *
     * @var WeakMap<Request, array<string, bool>>|null
     */
    private static ?WeakMap $siteDomainsByRequest = null;

    /**
     * The application, asset and storage hosts named in configuration.
     *
     * @return list<string>
     */
    public static function configured(): array
    {
        $container = Container::getInstance();

        if (! $container->bound('config')) {
            return [];
        }

        $config = $container->make(Repository::class);
        $urls = [$config->get('app.url'), $config->get('app.asset_url')];

        foreach ((array) $config->get('filesystems.disks', []) as $disk) {
            $urls[] = is_array($disk) ? ($disk['url'] ?? null) : null;
        }

        $hosts = [];

        foreach ($urls as $url) {
            $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;

            if (is_string($host) && $host !== '') {
                $hosts[] = strtolower($host);
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * True for a configured host, the host rendering the current request, or any
     * site's domain. Fails closed: when the domains cannot be read, the host is
     * treated as the site's own.
     */
    public static function contains(string $host): bool
    {
        $host = strtolower($host);

        $request = self::request();

        if (in_array($host, self::configured(), true) || $host === ($request instanceof Request ? strtolower($request->getHost()) : null)) {
            return true;
        }

        if (! $request instanceof Request) {
            return self::isSiteDomain($host);
        }

        self::$siteDomainsByRequest ??= new WeakMap;
        $answers = self::$siteDomainsByRequest[$request] ?? [];
        $answers[$host] ??= self::isSiteDomain($host);
        self::$siteDomainsByRequest[$request] = $answers;

        return $answers[$host];
    }

    private static function isSiteDomain(string $host): bool
    {
        try {
            return SiteDomain::query()->where('domain', $host)->exists();
        } catch (Throwable) {
            return true;
        }
    }

    private static function request(): ?Request
    {
        $container = Container::getInstance();

        if (! $container->bound('request')) {
            return null;
        }

        $request = $container->make('request');

        return $request instanceof Request ? $request : null;
    }
}
