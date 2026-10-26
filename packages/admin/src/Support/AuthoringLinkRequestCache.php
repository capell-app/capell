<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Core\Support\Permissions\SiteAccess;
use Closure;
use Spatie\Permission\PermissionRegistrar;

/** Shares presentation lookups only for the current request and access boundary. */
final class AuthoringLinkRequestCache
{
    /** @var array<string, mixed> */
    private array $items = [];

    /** @param list<mixed> $boundary */
    private function __construct(private readonly array $boundary) {}

    /**
     * @template T
     *
     * @param  Closure(SiteAccess): T  $resolver
     * @return T
     */
    public static function remember(string $key, Closure $resolver): mixed
    {
        $access = SiteAccess::current();
        $boundary = [auth()->user(), resolve(PermissionRegistrar::class)->getPermissionsTeamId(), $access->isGlobal(), $access->allowedSiteIds()];
        $request = request();
        $cache = $request->attributes->get(self::class);

        if (! $cache instanceof self || $cache->boundary !== $boundary) {
            $cache = new self($boundary);
            $request->attributes->set(self::class, $cache);
        }

        if (! array_key_exists($key, $cache->items)) {
            $cache->items[$key] = $resolver($access);
        }

        return $cache->items[$key];
    }
}
