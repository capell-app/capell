<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Filament\Concerns\HasCustomSelectOption;
use Capell\Admin\Filament\Resources\Blueprints\Schemas\BlueprintForm;
use Capell\Admin\Filament\Support\HelperText;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Contracts\Blueprintable;
use Closure;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Override;
use Throwable;

class BlueprintSelect extends SelectWithBelongsToRelation
{
    use HasCustomSelectOption;

    protected null|BlueprintSubjectEnum|string $type = null;

    protected ?Closure $modifySelectOptionsQueryUsing = null;

    protected ?Closure $afterEditOptionActionUpdated = null;

    protected ?Closure $afterCreateOptionActionCreated = null;

    #[Override]
    protected function setUp(?string $label = null): void
    {
        parent::setUp();

        $this->label(in_array($label, [null, '', '0'], true) ? __('capell-admin::form.type') : $label)
            ->reactive()
            ->required()
            ->allowHtml()
            ->selectablePlaceholder(false)
            ->default(
                function (BlueprintSelect $component): ?int {
                    /** @var class-string<Blueprint> $model */
                    $model = Blueprint::class;

                    return $model::query()
                        ->when(
                            $component->getBlueprint(),
                            fn (Builder $query, string $type): Builder => $query->where('type', $type),
                        )
                        ->when(
                            $this->modifySelectOptionsQueryUsing instanceof Closure,
                            fn (Builder $query): mixed => $this->evaluate($this->modifySelectOptionsQueryUsing, [
                                'query' => $query,
                                'record' => $this->getRecord(),
                            ]),
                        )
                        ->orderBy('default', 'desc')
                        ->value('id');
                },
            )
            ->afterLabel(fn (?int $state): array => $this->getBlueprintSelectLabelHelpers($state));
    }

    public function modifySelectOptionsQueryUsing(?Closure $callback): static
    {
        $this->modifySelectOptionsQueryUsing = $callback;

        return $this;
    }

    public function getBlueprint(): ?string
    {
        if ($this->type instanceof BlueprintSubjectEnum) {
            return $this->type->value;
        }

        return is_string($this->type) ? $this->type : null;
    }

    public function withCreateForm(): static
    {
        return $this->getOptionLabelFromRecordUsing(
            fn (Blueprint $record): string => static::getSelectOption($record),
        )
            ->createOptionForm(fn (Schema $schema): Schema => BlueprintForm::configure($schema))
            ->createOptionAction(
                fn (Action $action): Action => $action
                    ->fillForm(function (HasActions&HasSchemas $livewire, self $component): array {
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

                        $data['type'] = $component->getBlueprint();

                        return $data;
                    })
                    ->modalHeading(
                        fn (self $component): string => __(
                            'capell-admin::heading.create_type_type',
                            ['type' => $component->getBlueprint() ?? ''],
                        ),
                    )
                    ->slideOver()
                    ->modalWidth(Width::ScreenLarge)
                    ->visible(
                        fn (?int $state, self $component, string $operation): bool => ($state === null || $state === 0)
                            || (
                                ! $component->canSelectPlaceholder()
                                && (
                                    in_array($operation, ['create', 'createOption'], true)
                                    || ! $component->hasEditOptionActionFormSchema()
                                )
                            ),
                    )
                    ->successNotificationTitle(
                        fn (Action $action): string => __(
                            'capell-admin::notification.created_successfully',
                            ['name' => $this->actionModalHeading($action)],
                        ),
                    )
                    ->after(function (Action $action): void {
                        $this->callAfterCreateOptionActionCreated($action);
                        $action->success();
                    }),
            );
    }

    public function withEditForm(): static
    {
        return $this->editOptionForm(fn (Schema $schema): Schema => BlueprintForm::configure(
            $schema,
            $this->embeddedSelectEditContext(),
        ))
            ->editOptionAction(
                fn (Action $action): Action => $action
                    ->modalHeading(function (string $context, self $component, ?int $state): ?HtmlString {
                        if ($state === null || $state === 0) {
                            return null;
                        }

                        $selectedRecord = $component->getSelectedRecord();

                        if (! $selectedRecord instanceof Model) {
                            return null;
                        }

                        $selectedRecord = $this->resolveBlueprintRecord($selectedRecord);

                        return new HtmlString(
                            __('capell-admin::heading.edit_type_record', ['type' => $selectedRecord->name]),
                        );
                    })
                    ->modalDescription(fn (self $component): ?string => $this->editTypeModalDescription($component))
                    ->slideOver()
                    ->modalWidth(Width::ScreenLarge)
                    ->visible(
                        fn (?int $state, string $operation): bool => $state !== null && $state !== 0
                            && ! in_array($operation, ['create', 'createOption'], true),
                    )
                    ->successNotificationTitle(
                        fn (Action $action): string => __(
                            'capell-admin::notification.updated_successfully',
                            ['name' => $this->actionModalHeading($action)],
                        ),
                    )
                    ->fillForm(function (HasActions&HasSchemas $livewire, self $component): array {
                        $record = $component->getSelectedRecord();

                        if (! $record instanceof Blueprint) {
                            return [];
                        }

                        $schemaName = $livewire->getMountedActionSchemaName();

                        if ($schemaName === null) {
                            return [];
                        }

                        $schema = $livewire->getSchema($schemaName);

                        if (! $schema instanceof Schema) {
                            return [];
                        }

                        $data = $record->attributesToArray();

                        // Fix issue where type is cast to DTO
                        $data['type'] = $record->getRawOriginal('type');

                        $schema->fill($data);

                        return $this->arrayState($schema->getRawState());
                    })
                    ->after(function (Action $action): void {
                        $this->callAfterEditOptionActionUpdated($action);
                        $action->success();
                    }),
            )
            ->fillEditOptionActionFormUsing(static function (self $component): array {
                $record = $component->getSelectedRecord();

                return $record?->attributesToArray() ?? [];
            })
            ->updateOptionUsing(function (array $data, Schema $schema): void {
                $record = $schema->getRecord();
                $blueprint = $record instanceof Blueprint ? $record : $this->resolveSelectedBlueprintForEdit();

                if (! $blueprint instanceof Blueprint) {
                    return;
                }

                unset($data['creation_mode'], $data['name'], $data['key'], $data['type']);

                foreach (['admin', 'meta'] as $attribute) {
                    $existingValue = is_array($blueprint->{$attribute}) ? $blueprint->{$attribute} : [];
                    $submittedValue = is_array($data[$attribute] ?? null) ? $data[$attribute] : [];

                    if ($submittedValue !== []) {
                        $data[$attribute] = array_replace_recursive($existingValue, $submittedValue);
                    }
                }

                if ($data !== []) {
                    $blueprint->update($data);
                }
            });
    }

    public function withRelation(): static
    {
        return $this->relationship(
            name: 'blueprint',
            titleAttribute: 'name',
            modifyQueryUsing: fn (Builder $query): Builder => $query->select('blueprints.*')
                ->when(
                    $this->getBlueprint(),
                    fn (Builder $query, string $type): Builder => $query->where('type', $type),
                )
                ->when(
                    $this->modifySelectOptionsQueryUsing instanceof Closure,
                    fn (Builder $query): mixed => $this->evaluate($this->modifySelectOptionsQueryUsing, [
                        'query' => $query,
                        'record' => $this->getRecord(),
                    ]),
                )
                ->enabled()
                ->ordered(),
        )
            ->preload()
            ->savesBelongsToRelation();
    }

    public function afterEditOptionActionUpdated(callable $action): void
    {
        $this->afterEditOptionActionUpdated = Closure::fromCallable($action);
    }

    public function afterCreateOptionActionCreated(callable $action): void
    {
        $this->afterCreateOptionActionCreated = Closure::fromCallable($action);
    }

    protected function callAfterCreateOptionActionCreated(Action $action): void
    {
        if ($this->afterCreateOptionActionCreated instanceof Closure) {
            $this->evaluate($this->afterCreateOptionActionCreated, [
                'action' => $action,
            ]);
        }
    }

    protected function callAfterEditOptionActionUpdated(Action $action): void
    {
        if ($this->afterEditOptionActionUpdated instanceof Closure) {
            $this->evaluate($this->afterEditOptionActionUpdated, [
                'action' => $action,
            ]);
        }
    }

    /**
     * @return array<int, Icon>
     */
    private function getBlueprintSelectLabelHelpers(?int $state): array
    {
        $helpers = [];

        if (HelperText::enabled()) {
            $helpers[] = Icon::make(Heroicon::QuestionMarkCircle)
                ->tooltip(__('capell-admin::form.type_helper'));
        }

        if ($this->shouldShowConfiguratorPathHint()) {
            $helpers[] = Icon::make(Heroicon::OutlinedQuestionMarkCircle)
                ->color('gray')
                ->tooltip(fn (Icon $component): HtmlString => $this->getConfiguratorTypeHint($component, $state));
        }

        return $helpers;
    }

    private function shouldShowConfiguratorPathHint(): bool
    {
        try {
            return resolve(AdminSettings::class)->show_configurator_path_hints
                && config('capell-admin.show_configurator_type_hint', config('capell-admin.show_schema_type_hint', false)) === true;
        } catch (Throwable) {
            return false;
        }
    }

    private function getConfiguratorTypeHint(Icon $component, ?int $state): HtmlString
    {
        /** @var self $parentComponent */
        $parentComponent = $component->getContainer()->getParentComponent();

        /** @var class-string<Blueprint> $model */
        $model = Blueprint::class;

        $type = $model::query()->find($state, ['admin']);

        $configurator = $type?->admin['configurator'] ?? 'Default';

        $configuratorType = Str::of($parentComponent->getBlueprint() ?? '')->ucfirst()->plural()->toString();

        return new HtmlString(
            '<span style="word-wrap:break-word">'
            . AdminSurfaceLookup::configurator($configuratorType, $configurator)
            . '</span>',
        );
    }

    private function resolveBlueprintRecord(Model $record): Blueprint
    {
        if ($record instanceof Blueprint) {
            return $record;
        }

        throw_unless($record instanceof Blueprintable, Exception::class, 'Record is not typeable.');

        $record->loadMissing('blueprint');

        return $record->getRelation('blueprint');
    }

    private function embeddedSelectEditContext(): ConfiguratorContextData
    {
        $blueprint = $this->resolveSelectedBlueprintForEdit();

        return ConfiguratorContextData::forEmbeddedSelectEdit(
            target: ConfiguratorTypeEnum::Blueprint,
            resourceName: is_array($blueprint?->admin) ? ($blueprint->admin['type_configurator'] ?? null) : null,
            recordName: $blueprint?->name,
            recordKey: $blueprint?->key,
            recordType: $blueprint?->getRawOriginal('type'),
        );
    }

    private function resolveSelectedBlueprintForEdit(): ?Blueprint
    {
        $selectedRecord = $this->getSelectedRecord();
        $blueprint = $selectedRecord instanceof Model
            ? $this->resolveBlueprintRecord($selectedRecord)
            : null;

        if ($blueprint instanceof Blueprint) {
            return $blueprint;
        }

        if (is_numeric($this->getState())) {
            $blueprint = Blueprint::query()->find((int) $this->getState());
        }

        if ($blueprint instanceof Blueprint) {
            return $blueprint;
        }

        $parentRecord = $this->getRecord();
        $statePath = $this->getStatePath(isAbsolute: false);
        $typeId = $parentRecord instanceof Model && is_string($statePath)
            ? $parentRecord->getAttribute($statePath)
            : null;

        return is_numeric($typeId) ? Blueprint::query()->find((int) $typeId) : null;
    }

    private function editTypeModalDescription(self $component): ?string
    {
        $selectedRecord = $component->getSelectedRecord();

        if (! $selectedRecord instanceof Model) {
            return null;
        }

        $blueprint = $this->resolveBlueprintRecord($selectedRecord);

        return __('capell-admin::generic.edit_type_inline_description', [
            'name' => $blueprint->name,
            'key' => $blueprint->key,
            'type' => str((string) $blueprint->getRawOriginal('type'))->headline()->toString(),
        ]);
    }

    /**
     * @param  array<string, mixed>|Arrayable<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function arrayState(array|Arrayable $state): array
    {
        $state = $state instanceof Arrayable ? $state->toArray() : $state;

        return is_array($state) ? $state : [];
    }

    private function actionModalHeading(Action $action): string
    {
        $heading = $action->getModalHeading();

        if ($heading instanceof Htmlable) {
            return $heading->toHtml();
        }

        return $heading;
    }
}
