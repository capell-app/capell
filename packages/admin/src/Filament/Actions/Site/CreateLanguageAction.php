<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions\Site;

use Capell\Admin\Filament\Actions\CreateAction;
use Capell\Admin\Filament\Components\Forms\Site\AdditionalSiteLanguages;
use Capell\Admin\Filament\Resources\Languages\Schemas\LanguageForm;
use Capell\Core\Models\Language;
use Filament\Actions\Action;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Support\Facades\FilamentIcon;
use Override;

class CreateLanguageAction extends CreateAction
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::button.create_language'))
            ->schema(fn (Schema $schema): Schema => LanguageForm::configure($schema->model(Language::class)))
            ->color('primary')
            ->icon(FilamentIcon::resolve('form-builder::components.select.actions.create-option') ?? 'heroicon-m-plus')
            ->link()
            ->size(Size::ExtraSmall)
            ->modalHeading(__('filament-forms::components.select.actions.create_option.modal.heading'))
            ->modalSubmitActionLabel(__('filament-forms::components.select.actions.create_option.modal.actions.create.label'))
            ->action(static function (Action $action, Section $component, array $data, Schema $schema): void {
                $languagesComponent = $component->getContainer()->getComponent('languages');

                if (! $languagesComponent instanceof AdditionalSiteLanguages) {
                    return;
                }

                $record = new Language;
                $record->fill($data);
                $record->save();

                $schema->model($record)->saveRelationships();

                $createdOptionKey = $record->getKey();

                $languagesComponent->state(fn (array $state): array => array_merge($state, [$createdOptionKey]));
                $languagesComponent->callAfterStateUpdated();

                $action->callAfter();

                $schema->fill();

                $action->success();
            });
    }

    #[Override]
    public static function getDefaultName(): ?string
    {
        return 'createLanguage';
    }
}
