<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\ClusterPackage\src\Filament\Resources\Widgets;

use Capell\Admin\Tests\Fixtures\ClusterPackage\src\Filament\Clusters\FixtureCluster;
use Capell\Core\Models\Site;
use Filament\Resources\Resource;
use Override;

final class FixtureClusteredResource extends Resource
{
    /** @var class-string<FixtureCluster>|null */
    protected static ?string $cluster = FixtureCluster::class;

    #[Override]
    public static function getModel(): string
    {
        return Site::class;
    }

    #[Override]
    public static function canAccess(): bool
    {
        return true;
    }
}
