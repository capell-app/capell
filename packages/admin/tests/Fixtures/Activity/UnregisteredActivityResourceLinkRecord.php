<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Activity;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class UnregisteredActivityResourceLinkRecord extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    public $timestamps = false;

    protected $table = 'unregistered_activity_resource_link_records';

    protected $guarded = [];
}
