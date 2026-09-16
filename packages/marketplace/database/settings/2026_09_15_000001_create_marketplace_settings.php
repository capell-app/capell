<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $defaults = [
            'marketplace.owner_name' => null,
            'marketplace.owner_email' => null,
            'marketplace.owner_organisation' => null,
            'marketplace.security_notifications_enabled' => false,
            'marketplace.bug_notifications_enabled' => false,
            'marketplace.marketing_notifications_enabled' => false,
        ];

        foreach ($defaults as $setting => $default) {
            if (! $this->migrator->exists($setting)) {
                $this->migrator->add($setting, $default);
            }
        }
    }
};
