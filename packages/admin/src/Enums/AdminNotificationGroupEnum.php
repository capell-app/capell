<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

enum AdminNotificationGroupEnum: string
{
    case PackageOperations = 'package_operations';

    public function label(): string
    {
        return match ($this) {
            self::PackageOperations => (string) __('capell-admin::notification_groups.package_operations.label'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PackageOperations => (string) __('capell-admin::notification_groups.package_operations.description'),
        };
    }
}
