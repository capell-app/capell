<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\AdminTools;

interface AdminToolItem
{
    public const string TAG = 'capell-admin:admin-tool-items';

    public function render(): string;
}
