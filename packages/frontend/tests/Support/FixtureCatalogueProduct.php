<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Support;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FixtureCatalogueProduct extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'pages';

    protected $guarded = [];
}
