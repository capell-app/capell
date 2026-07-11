<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Autoload;

use Capell\Admin\Filament\Concerns\HideEmptyRelationManager;

final class HideEmptyRelationManagerTestManager extends HideEmptyRelationManagerParent
{
    use HideEmptyRelationManager;

    protected static string $relationship = 'siteDomains';
}
