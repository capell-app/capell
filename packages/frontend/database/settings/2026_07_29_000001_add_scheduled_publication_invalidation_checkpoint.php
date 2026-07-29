<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('frontend.scheduled_publication_invalidation_checkpoint')) {
            $this->migrator->add('frontend.scheduled_publication_invalidation_checkpoint', null);
        }
    }
};
