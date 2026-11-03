<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\Concerns\HasCapellTable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class SecurityScanRun extends Model
{
    use HasCapellTable;

    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'capell_security_scan_runs';

    protected $keyType = 'string';

    protected $guarded = [];
}
