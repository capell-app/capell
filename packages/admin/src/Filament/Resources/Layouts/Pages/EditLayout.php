<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Layouts\Pages;

use Capell\Admin\Actions\ReplicateLayoutAction;
use Capell\Admin\Enums\ListenerEnum;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Actions\CreateAction;
use Capell\Admin\Filament\Actions\DeleteAction;
use Capell\Admin\Filament\Actions\ReplicateAction;
use Capell\Admin\Filament\Concerns\HasConfigurableFormActionPosition;
use Capell\Admin\Filament\Concerns\HasCreateActionOnEditPage;
use Capell\Admin\Filament\Concerns\HasExtensibleRecordHeading;
use Capell\Admin\Filament\Concerns\Validate\LayoutValidation;
use Capell\Admin\Filament\Contracts\ValidatesDelete;
use Capell\Admin\Filament\Resources\Layouts\LayoutResource;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Layout;
use Filament\Actions\ActionGroup;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Override;

/**
 * @property Layout $record
 */
class EditLayout extends EditRecord implements ValidatesDelete
{
    use HasConfigurableFormActionPosition;
    use HasCreateActionOnEditPage;
    use HasExtensibleRecordHeading;
    use LayoutValidation;

    /** @return class-string<LayoutResource> */
    #[Override]
    public static function getResource(): string
    {
        /** @var class-string<LayoutResource> $resource */
        $resource = AdminSurfaceLookup::resource(ResourceEnum::Layout);

        return $resource;
    }

    #[Override]
    public function getTitle(): string|Htmlable
    {
        if (filled(static::$title)) {
            return static::$title;
        }

        return new HtmlString(
            __(
                'capell-admin::heading.edit_layout_record',
                ['name' => Str::limit($this->recordTitleText(), 40)],
            ),
        );
    }

    protected function afterSave(): void
    {
        $this->notifyEditRecordHeadingSaved();

        CapellCore::subscriberManager()->notifySubscribers(ListenerEnum::AfterSave, $this);
    }

    #[Override]
    protected function getActions(): array
    {
        return $this->getBaseHeaderActions();
    }

    /** @return array<int, mixed> */
    protected function getBaseHeaderActions(): array
    {
        return [
            RestoreAction::make(),
            DeleteAction::make()
                ->before(function (self $livewire, DeleteAction $action, Layout $record): void {
                    if (! $livewire->validateDelete($record)) {
                        $livewire->dispatch('delete-action-halted');
                        $action->halt();
                    }
                }),
            ForceDeleteAction::make(),
            ActionGroup::make([
                CreateAction::make()
                    ->slideOver()
                    ->redirectAfterCreate(),
                ReplicateAction::make()
                    ->replicaModelAction(ReplicateLayoutAction::class)
                    ->hidden($this->record->trashed()),
            ]),
        ];
    }

    protected function getPositionedFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
            $this->getCancelFormAction(),
        ];
    }

    /** @return array<int, mixed> */
    protected function getPositionedHeaderFormActions(): array
    {
        return [
            $this->getSaveFormAction()
                ->submit(null)
                ->action(fn (): mixed => $this->save()),
            $this->getCancelFormAction(),
        ];
    }

    protected function selectChangerItemLabel(Layout $model): string
    {
        return $model->name;
    }

    private function recordTitleText(): string
    {
        $title = $this->getRecordTitle();

        return $title instanceof Htmlable ? $title->toHtml() : $title;
    }
}
