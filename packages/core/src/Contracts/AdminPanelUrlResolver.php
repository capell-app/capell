<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

interface AdminPanelUrlResolver
{
    public function resolve(?string $panelId = null): ?string;
}
