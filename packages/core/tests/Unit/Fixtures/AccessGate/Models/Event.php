<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Unit\Fixtures\AccessGate\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// Mirror the package's basename and table without an optional package dependency.
final class Event extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'access_gate_events';
}
