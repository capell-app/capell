<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\ClusterPackage\src\Filament\Clusters;

use Filament\Clusters\Cluster;

final class FixtureCluster extends Cluster
{
    protected static ?string $slug = 'fixture-cluster';

    // Core silences the shared page flag for contributed pages that do not redeclare it; a cluster is the
    // sidebar entry, so it keeps its own.
    protected static bool $shouldRegisterNavigation = true;
}
