<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Activity;

use Capell\Admin\Settings\AdminSettings;
use Capell\Core\Contracts\ActivitySettingsReader;
use Throwable;

final class AdminActivitySettingsReader implements ActivitySettingsReader
{
    public function collectionEnabled(): bool
    {
        return $this->settings()->analytics_collection_enabled;
    }

    public function searchCollectionEnabled(): bool
    {
        return $this->settings()->analytics_search_collection_enabled;
    }

    public function retentionDays(): int
    {
        return max(1, min(7, $this->settings()->analytics_activity_retention_days));
    }

    private function settings(): AdminSettings
    {
        try {
            return AdminSettings::instance();
        } catch (Throwable) {
            return new AdminSettings;
        }
    }
}
