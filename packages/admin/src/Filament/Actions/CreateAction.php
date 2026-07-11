<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions;

use Capell\Admin\Actions\SetupSiteLanguageAction;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\CreateAction as BaseCreateAction;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Resource;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Contracts\TranslatableContentDriver;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Override;
use RuntimeException;

class CreateAction extends BaseCreateAction
{
    /** @var class-string<resource>|null */
    protected ?string $resource = null;

    protected bool $redirectAfterCreate = false;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->modal()
            ->closeModalByClickingAway(false)
            ->successRedirectUrl(function (Model $record, self $action): ?string {
                if (! $action->redirectAfterCreate) {
                    return null;
                }

                return $action->getResource()::getUrl(
                    'edit',
                    ['record' => $record->getKey()],
                );
            })
            ->schema(function (Schema $schema, self $action): Schema {
                // Prevent relationships being fill when creating a record from edit page.
                $action->record(fn (): null => null);
                $schema->model($action->getModel());

                /** @var class-string<resource> $resource */
                $resource = $action->getResource();

                return $resource::form($schema->operation('createOption'));
            })
            ->fillForm(
                /** @return array<string, mixed> */
                data: function (HasActions&HasSchemas $livewire): array {
                    $schemaName = $livewire->getMountedActionSchemaName();

                    if ($schemaName === null) {
                        return [];
                    }

                    $schema = $livewire->getSchema($schemaName);

                    if (! $schema instanceof Schema) {
                        return [];
                    }

                    $schema->fill();

                    $data = $this->arrayState($schema->getRawState());

                    return $this->mutateFormData($data);
                },
            )
            ->using(static::saveActionUsing(...));
    }

    #[Override]
    public function getContext(): array
    {
        $context = parent::getContext();

        if (isset($context['recordKey'])) {
            unset($context['recordKey']);
        }

        return $context;
    }

    public function redirectAfterCreate(bool $redirect = true): self
    {
        $this->redirectAfterCreate = $redirect;

        return $this;
    }

    /**
     * @param  class-string<resource>  $resource
     */
    public function resource(string $resource): self
    {
        if (! is_subclass_of($resource, Resource::class)) {
            throw new RuntimeException(sprintf('Resource [%s] must extend %s.', $resource, Resource::class));
        }

        $this->resource = $resource;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function saveActionUsing(array $data, HasActions&HasSchemas $livewire): Model
    {
        $setupSites = $data['setup_sites'] ?? [];
        $shouldSetupLanguage = ($data['setup'] ?? false) === true
            && is_array($setupSites)
            && $setupSites !== [];
        $data = Arr::except($data, ['setup', 'setup_sites']);

        /** @var Relation<Model, Model, mixed>|null $relationship */
        $relationship = $this->getRelationship();

        $pivotData = [];

        if ($relationship instanceof BelongsToMany) {
            $pivotColumns = $relationship->getPivotColumns();

            $pivotData = Arr::only($data, $pivotColumns);
            $data = Arr::except($data, $pivotColumns);
        }

        $model = $this->getRequiredModel();

        if (($translatableContentDriver = $livewire->makeFilamentTranslatableContentDriver()) instanceof TranslatableContentDriver) {
            $record = $translatableContentDriver->makeRecord($model, $data);
        } else {
            $record = new $model;
            $record->fill($data);
        }

        $this->mutateRecordBeforeSave($record, $data);

        if (
            ($relationship === null) ||
            ($relationship instanceof HasOneOrManyThrough)
        ) {
            $record->save();
        } elseif ($relationship instanceof BelongsToMany) {
            $relationship->save($record, $pivotData);
        } else {
            /** @var HasOneOrMany<Model, Model, mixed> $relationship */
            $relationship->save($record);
        }

        if ($record instanceof Language && $shouldSetupLanguage) {
            /** @var Builder<Site> $siteQuery */
            $siteQuery = SiteScope::applyForCurrentActor(Site::query(), 'id')
                ->whereIn('id', $setupSites);

            $siteQuery->each(function (Site $site) use ($record): void {
                SetupSiteLanguageAction::run($site, $record);
            });
        }

        return $record;
    }

    /**
     * @return class-string<resource>
     */
    protected function getResource(): string
    {
        if (filled($this->resource)) {
            return $this->resource;
        }

        $livewire = $this->getLivewire();

        if ($livewire instanceof EditRecord || $livewire instanceof CreateRecord || $livewire instanceof ListRecords) {
            $resource = $livewire->getResource();

            if (! is_subclass_of($resource, Resource::class)) {
                throw new RuntimeException(sprintf('Resource [%s] must extend %s.', $resource, Resource::class));
            }

            return $resource;
        }

        throw new RuntimeException('Resource not set for action: ' . ($livewire !== null ? $livewire::class : self::class));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormData(array $data): array
    {
        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateRecordBeforeSave(Model $record, array $data): array
    {
        return $data;
    }

    /**
     * @param  array<string, mixed>|Arrayable<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function arrayState(array|Arrayable $state): array
    {
        return $state instanceof Arrayable ? $state->toArray() : $state;
    }

    /**
     * @return class-string<Model>
     */
    private function getRequiredModel(): string
    {
        $model = $this->getModel();

        throw_if($model === null, RuntimeException::class, 'Model not set for action.');

        return $model;
    }
}
