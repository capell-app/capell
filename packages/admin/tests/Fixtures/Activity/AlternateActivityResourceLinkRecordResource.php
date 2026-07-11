<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Activity;

use Filament\Resources\Resource;

final class AlternateActivityResourceLinkRecordResource extends Resource
{
    protected static ?string $model = ActivityResourceLinkRecord::class;

    protected static ?string $slug = 'alternate-activity-link-records';
}
