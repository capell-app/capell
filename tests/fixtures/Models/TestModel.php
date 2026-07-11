<?php

declare(strict_types=1);

namespace Capell\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 *
 * @mixin Model
 */
class TestModel extends Model
{
    use HasFactory;

    public $timestamps = true;

    protected $table = 'test_models';

    protected $guarded = [];
}
