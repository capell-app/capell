<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\Concerns\HasCapellTable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string|null $started_at
 * @property string|null $finished_at
 * @property int|null $findings_count
 * @property string $source
 */
final class SecurityScanRun extends Model
{
    use HasCapellTable;

    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'capell_security_scan_runs';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'started_at',
        'finished_at',
        'findings_count',
        'source',
    ];
}
