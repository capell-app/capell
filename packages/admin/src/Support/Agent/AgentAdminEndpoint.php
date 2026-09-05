<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Agent;

use Capell\Admin\Support\AdminPanelEntrypoint;

final class AgentAdminEndpoint
{
    public static function path(string $suffix): string
    {
        $parts = array_filter([
            trim(AdminPanelEntrypoint::path(), '/'),
            'agent/v1',
            trim($suffix, '/'),
        ], static fn (string $part): bool => $part !== '');

        return '/' . implode('/', $parts);
    }
}
