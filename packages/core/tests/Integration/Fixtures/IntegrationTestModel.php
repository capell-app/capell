<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Integration\Fixtures;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string|null $key
 * @property string|null $name
 * @property string|null $type
 * @property string|null $updated
 * @property bool|null $intercepted
 */
class IntegrationTestModel extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'integration_test_models';

    protected $fillable = ['name', 'updated', 'type', 'key'];
}
