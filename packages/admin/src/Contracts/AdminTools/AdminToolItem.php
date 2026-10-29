<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\AdminTools;

/** @experimental */
interface AdminToolItem
{
    public const string TAG = 'capell-admin:admin-tool-items';

    public function render(): string;
}
