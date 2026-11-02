<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Extensions;

use Capell\Admin\Actions\Extensions\BuildExtensionOperationsSummaryAction;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cheap, cross-request counts for the Extensions navigation badge.
 *
 * Building the full operations summary probes every package and every
 * screenshot asset, so the badge reads these cached counts instead. They are
 * scoped to the actor's team and site access and invalidated by bumping a
 * version whenever extensions or their health alerts change.
 */
class ExtensionNavigationBadgeCounts
{
    private const string VERSION_KEY = 'capell:admin:extensions-nav-badge:version';

    private const int TTL_SECONDS = 60;

    /** @var array<string, array{unhealthy: int, blocked: int}> */
    private array $resolved = [];

    public function unhealthy(): int
    {
        return $this->counts()['unhealthy'];
    }

    public function blocked(): int
    {
        return $this->counts()['blocked'];
    }

    public function forget(): void
    {
        $this->resolved = [];
        $this->store()->forever(self::VERSION_KEY, (string) microtime(true));
    }

    /** @return array{unhealthy: int, blocked: int} */
    protected function build(): array
    {
        $summary = BuildExtensionOperationsSummaryAction::run();

        return ['unhealthy' => $summary->unhealthyCount, 'blocked' => $summary->blockedCount];
    }

    /** @return array{unhealthy: int, blocked: int} */
    private function counts(): array
    {
        $key = $this->key();

        return $this->resolved[$key] ??= $this->store()->remember($key, self::TTL_SECONDS, fn (): array => $this->build());
    }

    private function key(): string
    {
        $access = SiteAccess::current();
        $scope = json_encode([
            auth()->id(),
            resolve(PermissionRegistrar::class)->getPermissionsTeamId(),
            $access->isGlobal(),
            $access->allowedSiteIds(),
        ]);

        return 'capell:admin:extensions-nav-badge:' . $this->store()->get(self::VERSION_KEY, '0') . ':' . hash('xxh128', (string) $scope);
    }

    private function store(): Repository
    {
        return Cache::store();
    }
}
