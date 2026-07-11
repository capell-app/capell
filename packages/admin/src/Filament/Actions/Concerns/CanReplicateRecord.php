<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions\Concerns;

use Capell\Admin\Actions\ReplicateModelAction;
use Capell\Admin\Filament\Components\Forms\KeyTextInput;
use Capell\Admin\Filament\Components\Forms\NameInput;
use Capell\Core\Actions\GenerateUniqueKeyAction;
use Capell\Core\Actions\IncrementNameAction;
use Capell\Core\Support\Slug\SlugGenerator;
use Filament\Actions\ReplicateAction;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rules\Unique;

/**
 * @mixin ReplicateAction
 */
trait CanReplicateRecord
{
    /**
     * @var class-string
     */
    protected string $replicaModelActionClass = ReplicateModelAction::class;

    /**
     * @param  class-string  $action
     */
    public function replicaModelAction(string $action): self
    {
        $this->replicaModelActionClass = $action;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function replicateRecordData(Model $record, array $data): array
    {
        $data['name'] = $this->incrementName((string) $record->getAttribute('name'), $record);

        $attributes = $record->getAttributes();

        $hasKey = isset($attributes['key']);

        if ($hasKey) {
            $data['key'] = GenerateUniqueKeyAction::run($record);
        }

        return $data;
    }

    protected function replicateRecordAction(ReplicateAction $action): mixed
    {
        $result = $action->process(function (Model $record, array $data, self $action): void {
            /** @var class-string $replicaModelActionClass */
            $replicaModelActionClass = $action->replicaModelActionClass;
            $data = Arr::except($data, $action->getExcludedAttributes() ?? []);

            $action->replica = $replicaModelActionClass::run($record, $data);
        });

        try {
            return $result;
        } finally {
            $this->success();
        }
    }

    /**
     * @param  array<int, Component>  $components
     */
    protected function replicateRecordSchema(ReplicateAction $action, Model $record, Schema $schema, array $components = []): Schema
    {
        $attributes = $record->getAttributes();

        $hasKey = isset($attributes['key']);

        if ($hasKey) {
            $components = [
                NameInput::make('name')
                    ->afterStateUpdatedJs(
                        fn (NameInput $component): string => SlugGenerator::slugifyState("\$state ?? ''", 'key'),
                    ),

                KeyTextInput::make()
                    ->unique(
                        table: $record->getTable(),
                        modifyRuleUsing: fn (Unique $rule) => in_array(
                            SoftDeletes::class,
                            class_uses($record),
                            true,
                        )
                            ? $rule->withoutTrashed()
                            : $rule,
                    ),
                ...$components,
            ];
        } else {
            $components = [
                NameInput::make('name'),
                ...$components,
            ];
        }

        $size = $action->getSize();
        $useOneColumn = in_array($size, [null, '', Size::Small], true);

        return $schema->columns($useOneColumn ? 1 : 2)->schema($components);
    }

    private function incrementName(string $name, Model $model): string
    {
        $name = IncrementNameAction::run($name);

        $query = $model::query();

        while ($query->clone()->where('name', $name)->exists()) {
            $name = IncrementNameAction::run($name);
        }

        return $name;
    }
}
