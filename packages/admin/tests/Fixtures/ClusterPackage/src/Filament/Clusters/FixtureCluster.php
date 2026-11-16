<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\ClusterPackage\src\Filament\Clusters;

use Filament\Clusters\Cluster;

final class FixtureCluster extends Cluster
{
    protected static ?string $slug = 'fixture-cluster';
}
