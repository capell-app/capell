<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Publishing;

use Capell\Core\Models\Concerns\HasStatus;
use Capell\Core\Models\Contracts\Statusable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Minimal Statusable-only record (NOT Publishable) for exercising the publish
 * panel's status-only rendering path.
 *
 * @property bool $status
 */
final class StatusOnlyRecord extends Model implements Statusable
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    /** @use HasStatus<self> */
    use HasStatus;

    public $timestamps = false;

    protected $table = 'status_only_records';

    protected $guarded = [];
}
