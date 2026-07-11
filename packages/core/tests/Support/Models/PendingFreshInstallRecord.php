<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class PendingFreshInstallRecord extends Model
{
    use HasFactory;

    protected $table = 'pending_fresh_install_records';
}
