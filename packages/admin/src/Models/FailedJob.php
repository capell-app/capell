<?php

declare(strict_types=1);

namespace Capell\Admin\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

final class FailedJob extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    public $timestamps = false;

    #[Override]
    public function getTable(): string
    {
        return config('queue.failed.table', 'failed_jobs');
    }
}
