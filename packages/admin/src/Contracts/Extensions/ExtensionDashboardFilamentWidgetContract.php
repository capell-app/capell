<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extensions;

use Capell\Admin\Enums\DashboardEnum;

interface ExtensionDashboardFilamentWidgetContract
{
    public static function settingsKey(): string;

    public static function getLabel(): string;

    public static function getDescription(): ?string;

    /** @return array<string, int> */
    public static function defaultSpan(): array;

    public static function defaultOrder(): int;

    public static function dashboardScope(): DashboardEnum;

    public static function canView(): bool;
}
