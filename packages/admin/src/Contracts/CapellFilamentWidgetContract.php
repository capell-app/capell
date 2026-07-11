<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts;

interface CapellFilamentWidgetContract
{
    public static function canView(): bool;

    public static function settingsKey(): string;

    /** @return list<string> */
    public static function rolesConfigKeys(): array;
}
