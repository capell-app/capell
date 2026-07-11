<?php

declare(strict_types=1);

namespace Capell\Admin\Facades;

use Capell\Admin\Support\CapellAdminManager;
use Illuminate\Support\Facades\Facade;

/**
 * @mixin CapellAdminManager
 */
class CapellAdmin extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CapellAdminManager::class;
    }
}
