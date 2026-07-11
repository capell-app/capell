<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Autoload;

use Capell\Admin\Filament\Concerns\HasRelationManagerBadge;

final class RelationManagerBadgeTestManager
{
    use HasRelationManagerBadge;

    protected static string $relationship = 'siteDomains';
}
