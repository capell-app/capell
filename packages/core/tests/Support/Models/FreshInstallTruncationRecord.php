<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class FreshInstallTruncationRecord extends Model
{
    use HasFactory;

    protected $table = 'fresh_install_truncation_records';
}
