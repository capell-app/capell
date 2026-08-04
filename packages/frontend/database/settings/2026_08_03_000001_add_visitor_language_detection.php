<?php

declare(strict_types=1);

use Capell\Frontend\Enums\VisitorLanguageDetection;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('frontend.visitor_language_detection')) {
            $this->migrator->add('frontend.visitor_language_detection', VisitorLanguageDetection::Off->value);
        }
    }
};
