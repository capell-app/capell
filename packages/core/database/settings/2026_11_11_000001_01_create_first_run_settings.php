<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $defaults = [
            'first_run.suite_key' => null,
            'first_run.business_type' => null,
            'first_run.preset_key' => null,
            'first_run.setup_completed_at' => null,
            'first_run.published_at' => null,
        ];

        foreach ($defaults as $key => $value) {
            if (! $this->migrator->exists($key)) {
                $this->migrator->add($key, $value);
            }
        }
    }
};
