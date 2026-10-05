<?php

declare(strict_types=1);

namespace Capell\Tests\Fixtures\Activity;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Activitylog\Contracts\Activity;

final class ContractActivity extends ActivityRecord implements Activity
{
    use HasFactory;
}
