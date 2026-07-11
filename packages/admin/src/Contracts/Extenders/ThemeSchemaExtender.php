<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extenders;

use Capell\Admin\Enums\SchemaExtenderEnum;
use Filament\Schemas\Schema;

interface ThemeSchemaExtender
{
    public const string TAG = SchemaExtenderEnum::Theme->value;

    /**
     * @param  array<int, mixed>  $components
     * @return array<int, mixed>
     */
    public function extendSettingsComponents(Schema $schema, array $components): array;
}
