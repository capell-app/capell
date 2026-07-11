<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Autoload;

use Capell\Admin\Support\Bridges\AbstractUserResourceBridge;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Model;
use Override;

final class FullUserResourceBridgeForResolverTest extends AbstractUserResourceBridge
{
    #[Override]
    public function columns(): array
    {
        return [TextColumn::make('bridge_column')];
    }

    #[Override]
    public function filters(): array
    {
        return [Filter::make('bridge_filter')];
    }

    #[Override]
    public function recordActions(): array
    {
        return [Action::make('bridge_record_action')];
    }

    #[Override]
    public function toolbarActions(): array
    {
        return [Action::make('bridge_toolbar_action')];
    }

    #[Override]
    public function mutateDataBeforeCreate(array $data): array
    {
        $data['bridge_created'] = true;

        return $data;
    }

    #[Override]
    public function afterCreate(Model $record): void
    {
        $record->setAttribute('bridge_after_create', true);
    }

    #[Override]
    public function mutateDataBeforeSave(Model $record, array $data): array
    {
        $data['bridge_saved'] = true;

        return $data;
    }

    #[Override]
    public function afterSave(Model $record): void
    {
        $record->setAttribute('bridge_after_save', true);
    }
}
