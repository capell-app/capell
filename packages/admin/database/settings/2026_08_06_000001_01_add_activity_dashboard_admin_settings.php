<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $defaults = [
            'analytics_collection_enabled' => true,
            'analytics_search_collection_enabled' => false,
            'analytics_activity_retention_days' => 1,
            'analytics_default_period_days' => 7,
            'analytics_refresh_interval_seconds' => 60,
            'analytics_top_n_limit' => 10,
        ];

        foreach ($defaults as $key => $value) {
            if (! $this->migrator->exists('admin.' . $key)) {
                $this->migrator->add('admin.' . $key, $value);
            }
        }
    }
};
