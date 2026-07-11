<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts;

interface DashboardSettingsContributor
{
    public const string TAG = 'capell.dashboard.settings_contributor';

    /**
     * Return settings keys this contributor provides for dashboard composition.
     *
     * @return list<array{key: string, label: string, group: string, description?: string}>
     */
    public function settingsKeys(): array;
}
