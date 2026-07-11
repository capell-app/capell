<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Extenders\UserFormExtender;
use Capell\Admin\Contracts\Extenders\UserTableExtender;
use Capell\Admin\Data\Schemas\UserSchemaContextData;
use Capell\Admin\Support\Bridges\UserResourceBridgeResolver;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

it('resolves legacy user form and table extenders when explicit context is supplied', function (): void {
    app()->bind('legacy.user-form-extender', fn (): UserFormExtender => new class implements UserFormExtender
    {
        public function mutateDataBeforeCreate(array $data): array
        {
            $data['created_by_legacy'] = true;

            return $data;
        }

        public function afterCreate(Model $record): void
        {
            $record->setAttribute('legacy_after_create', true);
        }

        public function mutateDataBeforeSave(Model $record, array $data): array
        {
            $data['saved_by_legacy'] = true;

            return $data;
        }

        public function afterSave(Model $record): void
        {
            $record->setAttribute('legacy_after_save', true);
        }
    });

    app()->bind('legacy.user-table-extender', fn (): UserTableExtender => new class implements UserTableExtender
    {
        public function columns(): array
        {
            return [TextColumn::make('legacy_column')];
        }

        public function filters(): array
        {
            return [];
        }

        public function recordActions(): array
        {
            return [Action::make('legacy_record_action')];
        }

        public function toolbarActions(): array
        {
            return [Action::make('legacy_toolbar_action')];
        }
    });

    app()->tag(['legacy.user-form-extender'], UserFormExtender::TAG);
    app()->tag(['legacy.user-table-extender'], UserTableExtender::TAG);

    $resolver = new UserResourceBridgeResolver;
    $record = new class extends Model
    {
        /** @use HasFactory<Factory<static>> */
        use HasFactory;
    };
    $context = UserSchemaContextData::forEdit($record, [], 'default', 'users');

    $resolver->afterCreate($record, $context);
    $resolver->afterSave($record, $context);

    expect($resolver->mutateDataBeforeCreate([], $context))->toHaveKey('created_by_legacy', true)
        ->and($resolver->mutateDataBeforeSave($record, [], $context))->toHaveKey('saved_by_legacy', true)
        ->and($record->getAttribute('legacy_after_create'))->toBeTrue()
        ->and($record->getAttribute('legacy_after_save'))->toBeTrue()
        ->and($resolver->columns($context)[0]->getName())->toBe('legacy_column')
        ->and($resolver->recordActions($context)[0])->toBeInstanceOf(Action::class)
        ->and($resolver->toolbarActions($context)[0])->toBeInstanceOf(Action::class);
});
