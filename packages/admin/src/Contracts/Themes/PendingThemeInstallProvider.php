<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Themes;

interface PendingThemeInstallProvider
{
    public const string TAG = 'capell.admin.pending-theme-install-provider';

    /**
     * @return list<array{name: string, package: string, command: string}>
     */
    public function pendingThemeInstalls(): array;
}
