<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Layouts\Pages;

use Capell\Admin\Actions\Layouts\BuildLayoutImpactPreviewAction;
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
use Capell\Admin\Support\Layouts\LayoutCardData;
use Capell\Core\Actions\ContentGraph\ReconcileContentImpactAction;
use Capell\Core\Data\EditorImpact\EditorImpactPreviewData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Layout;
use Filament\Actions\ActionGroup;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
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

    #[Locked]
    public ?string $impactPlanFingerprint = null;

    /** @var list<string> */
    #[Locked]
    public array $impactPlanSurfaces = [];

    /** @return class-string<LayoutResource> */
    #[Override]
    public static function getResource(): string
    {
        /** @var class-string<LayoutResource> $resource */
        $resource = AdminSurfaceLookup::resource(ResourceEnum::Layout);

        return $resource;
    }

    #[Override]
    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->rememberImpactPlan();
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

    #[Override]
    public function getSubheading(): string|Htmlable|null
    {
        $card = LayoutCardData::fromLayout($this->record);

        return new HtmlString(view('capell-admin::components.record-state-summary', [
            'states' => $card->states(),
            'relationships' => $card->relationships(),
        ])->render());
    }

    protected function afterSave(): void
    {
        $this->reconcileImpactPlan();
        $this->notifyEditRecordHeadingSaved();

        CapellCore::subscriberManager()->notifySubscribers(ListenerEnum::AfterSave, $this);
    }

    protected function beforeSave(): void
    {
        $preview = BuildLayoutImpactPreviewAction::run($this->record);

        if ($preview === null
            || $this->impactPlanFingerprint === null
            || ! hash_equals($this->impactPlanFingerprint, $preview->fingerprint)) {
            throw ValidationException::withMessages([
                'data.impactPlanFingerprint' => __('capell-admin::message.impact_plan_stale'),
            ]);
        }

        $this->impactPlanSurfaces = $preview->surfaceKeys();
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
                ->action(function (): void {
                    $this->save();
                }),
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

    private function rememberImpactPlan(): void
    {
        $preview = BuildLayoutImpactPreviewAction::run($this->record);

        $this->impactPlanFingerprint = $preview?->fingerprint;
        $this->impactPlanSurfaces = $preview?->surfaceKeys() ?? [];
    }

    private function reconcileImpactPlan(): void
    {
        if ($this->impactPlanFingerprint === null) {
            return;
        }

        $this->record->refresh();
        $actualPreview = BuildLayoutImpactPreviewAction::run($this->record);

        if (! $actualPreview instanceof EditorImpactPreviewData) {
            return;
        }

        ReconcileContentImpactAction::run(
            $this->record,
            $this->impactPlanSurfaces,
            $actualPreview->surfaceKeys(),
        );

        $this->impactPlanFingerprint = $actualPreview->fingerprint;
        $this->impactPlanSurfaces = $actualPreview->surfaceKeys();
    }
}
