<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Configurators\Layouts;

use Capell\Admin\Contracts\ConfiguratorInterface;
use Capell\Admin\Contracts\ConfiguratorTypeEnumInterface;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Enums\SchemaExtenderEnum;
use Capell\Admin\Filament\Components\Forms\Layout\DetailsSchema;
use Capell\Admin\Filament\Components\Forms\Layout\Tab\SettingsTab;
use Capell\Admin\Filament\Concerns\HasConfigurator;
use Capell\Admin\Support\Schemas\AdminSchemaExtensionPipeline;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;

class DefaultLayoutConfigurator implements ConfiguratorInterface
{
    use HasConfigurator;

    protected static ConfiguratorTypeEnumInterface $configuratorType = ConfiguratorTypeEnum::Layout;

    /** @return iterable<int, mixed> */
    public static function getExtenders(): iterable
    {
        return app()->tagged(SchemaExtenderEnum::Layout->value);
    }

    /** @return array<int, mixed> */
    public function make(Schema $schema): array
    {
        return match ($schema->getOperation()) {
            'create', 'createOption', 'replicate' => $this->getCreateFormSchema($schema),
            default => $this->getEditFormSchema($schema),
        };
    }

    /** @return array<int, mixed> */
    protected function getCreateFormSchema(Schema $schema): array
    {
        return (new DetailsSchema)->make($schema);
    }

    /** @return array<int, mixed> */
    protected function getEditFormSchema(Schema $schema): array
    {
        return [
            Tabs::make()
                ->columnSpanFull()
                ->tabs($this->getTabs($schema)),
        ];
    }

    /** @return array<int, mixed> */
    protected function getTabs(Schema $schema): array
    {
        return resolve(AdminSchemaExtensionPipeline::class)->layoutTabs($schema, [
            SettingsTab::make($schema),
        ]);
    }
}
