<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class UnavailableModel extends Model
{
    use HasFactory;

    protected $table = 'unavailable_pageables';
}
