<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Upgrade;

use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Core\Data\UpgradeContext;
use Capell\Core\Support\Upgrade\AbstractUpgradeStep;
use Override;
use RuntimeException;

/**
 * Routes now record who serves them when they are registered, and `route:cache` stores that record. A
 * route cache built by an earlier release has none, so admin routes are refused until it is rebuilt: the
 * upgrade fails with the commands that rebuild it rather than letting every node serve from it.
 */
final class RequireRecordedRouteCacheStep extends AbstractUpgradeStep
{
    public function __construct(private readonly InstalledPanelRuntime $runtime) {}

    #[Override]
    public function id(): string
    {
        return 'capell-admin.route-cache-ownership';
    }

    #[Override]
    public function label(): string
    {
        return __('capell-admin::message.upgrade_route_cache_ownership');
    }

    #[Override]
    public function package(): string
    {
        return 'capell-app/admin';
    }

    #[Override]
    public function run(UpgradeContext $context): bool
    {
        throw_if(app()->routesAreCached() && $this->runtime->unrecordedRoutes() !== [], RuntimeException::class, __('capell-admin::message.route_cache_unrecorded'));

        return true;
    }
}
