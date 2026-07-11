<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Integration\Fixtures;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** @property int $id */
class DummyNonCacheableModel extends Model
{
    use HasFactory;
}
