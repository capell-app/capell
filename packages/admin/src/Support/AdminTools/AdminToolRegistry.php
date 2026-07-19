<?php

declare(strict_types=1);

namespace Capell\Admin\Support\AdminTools;

use Capell\Admin\Contracts\AdminTools\AdminToolItem;
use Capell\Core\Support\Registries\TaggedProviderRegistry;
use Illuminate\Contracts\Foundation\Application;

/** @extends TaggedProviderRegistry<AdminToolItem> */
final class AdminToolRegistry extends TaggedProviderRegistry
{
    public function __construct(Application $application)
    {
        parent::__construct(self::tagged($application, AdminToolItem::TAG), AdminToolItem::class);
    }

    /** @return list<AdminToolItem> */
    public function all(): array
    {
        return $this->providers();
    }
}
