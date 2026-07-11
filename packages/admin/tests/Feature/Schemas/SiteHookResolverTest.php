<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Schemas;

use Capell\Admin\Contracts\Extenders\SiteSchemaExtender;
use Capell\Admin\Enums\PageTranslationSchemaHookEnum;
use Capell\Admin\Enums\SiteCreateWizardHookEnum;
use Capell\Admin\Support\Schemas\AdminSchemaExtensionPipeline;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

it('resolves site translation hook components from tagged extenders', function (): void {
    // Create an on-the-fly extender implementing the SiteSchemaExtender contract
    $extender = new class implements SiteSchemaExtender
    {
        public function extendTranslationComponentsForHook(Schema $schema, PageTranslationSchemaHookEnum $hook): array
        {
            if ($hook !== PageTranslationSchemaHookEnum::AfterTitle) {
                return [];
            }

            return [Textarea::make('site-hook-test')];
        }

        public function extendRelationManagers(Model $record, array $relationManagers): array
        {
            return $relationManagers;
        }

        public function extendTabs(Schema $schema, array $tabs): array
        {
            return $tabs;
        }

        public function extendSiteMetaDetailsComponents(Schema $schema, array $components): array
        {
            return $components;
        }

        public function extendCreateWizardComponentsForHook(Schema $schema, SiteCreateWizardHookEnum $hook): array
        {
            return [];
        }
    };

    // Bind the anonymous extender instance to the container under the interface
    // then tag the interface so Laravel's container can resolve tagged services.
    $this->app->instance(SiteSchemaExtender::class, $extender);
    $this->app->tag([SiteSchemaExtender::class], SiteSchemaExtender::TAG);

    $pipeline = $this->app->make(AdminSchemaExtensionPipeline::class);

    $components = $pipeline->siteTranslationComponentsForHook(Schema::make(), PageTranslationSchemaHookEnum::AfterTitle);

    expect($components)->not->toBeEmpty();
});
