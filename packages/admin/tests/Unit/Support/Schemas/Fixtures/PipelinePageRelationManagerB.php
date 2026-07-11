<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Support\Schemas\Fixtures;

use Filament\Resources\RelationManagers\RelationManager;

final class PipelinePageRelationManagerB extends RelationManager
{
    protected static string $relationship = 'pageB';
}
