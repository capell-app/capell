<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

use Capell\Admin\Contracts\Themes\PendingThemeInstallProvider;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallIntent;
use Illuminate\Support\Facades\Schema;

final class PendingMarketplaceThemeInstallProvider implements PendingThemeInstallProvider
{
    /**
     * @return list<array{name: string, package: string, command: string}>
     */
    public function pendingThemeInstalls(): array
    {
        if (! Schema::hasTable('marketplace_install_intents')) {
            return [];
        }

        return array_values(MarketplaceInstallIntent::query()
            ->where('kind', 'theme')
            ->where('status', MarketplaceInstallIntentStatus::Pending)
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (MarketplaceInstallIntent $intent): array => [
                'name' => $intent->extension_name,
                'package' => $intent->composer_name,
                'command' => $intent->composer_command,
            ])
            ->values()
            ->all());
    }
}
