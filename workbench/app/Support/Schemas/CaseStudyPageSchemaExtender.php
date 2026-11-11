<?php

declare(strict_types=1);

namespace Workbench\App\Support\Schemas;

use Capell\Admin\Support\Schemas\AbstractPageSchemaExtender;
use Capell\Core\Models\Page;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Override;

final class CaseStudyPageSchemaExtender extends AbstractPageSchemaExtender
{
    #[Override]
    public function extendTabs(Schema $schema, array $tabs): array
    {
        $page = $schema->getRecord();

        if (! $page instanceof Page || $page->blueprint?->key !== 'case-study') {
            return $tabs;
        }

        return [
            ...$tabs,
            Tab::make(__('custom-page-fields::fields.case_study'))
                ->schema([
                    TextInput::make('meta.case_study.client_name')
                        ->label(__('custom-page-fields::fields.client_name'))
                        ->helperText(__('custom-page-fields::fields.client_help'))
                        ->required()
                        ->maxLength(120),
                ]),
        ];
    }
}
