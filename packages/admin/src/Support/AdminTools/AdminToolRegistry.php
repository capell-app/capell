<?php

declare(strict_types=1);

namespace Capell\Admin\Support\AdminTools;

use Capell\Admin\Contracts\AdminTools\AdminToolItem;

class AdminToolRegistry
{
    /** @return iterable<AdminToolItem> */
    public function all(): iterable
    {
        return app()->tagged(AdminToolItem::TAG);
    }
}
