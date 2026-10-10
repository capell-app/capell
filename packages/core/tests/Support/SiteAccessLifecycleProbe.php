<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Support\Permissions\SiteAccess;
use Capell\Core\Tests\Support\Models\HasSitePermissionsTestUser;
use Illuminate\Contracts\Queue\ShouldQueue;

final class SiteAccessLifecycleProbe implements ShouldQueue
{
    /** @var list<list<int>|null> */
    public static array $observations = [];

    public function __construct(public int $actorId, public int $siteId) {}

    public function handle(): void
    {
        auth()->setUser(HasSitePermissionsTestUser::query()->findOrFail($this->actorId));
        setPermissionsTeamId($this->siteId);
        self::$observations[] = SiteAccess::current()->allowedSiteIds();
    }
}
