<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Livewire;

use Capell\Admin\Actions\Pages\ResolvePagePublishStateAction;
use Capell\Admin\Actions\Pages\ResolvePublishPanelViewAction;
use Capell\Admin\Actions\Publishing\BuildPublishReadinessAction;
use Capell\Admin\Actions\Publishing\CancelScheduledRecordUnpublishAction;
use Capell\Admin\Actions\Publishing\PublishRecordAction;
use Capell\Admin\Actions\Publishing\RevertRecordToDraftAction;
use Capell\Admin\Actions\Publishing\ScheduleRecordPublishAction;
use Capell\Admin\Actions\Publishing\ScheduleRecordUnpublishAction;
use Capell\Admin\Actions\Publishing\ToggleRecordStatusAction;
use Capell\Admin\Actions\Publishing\UnpublishRecordAction;
use Capell\Admin\Contracts\Extenders\PublishPanelExtender;
use Capell\Admin\Data\Pages\PublishPanelViewData;
use Capell\Admin\Data\Pages\PublishVisibilityActionResultData;
use Capell\Admin\Data\Publishing\PublishReadinessData;
use Capell\Core\Data\Publishing\PublicationTransitionResultData;
use Capell\Core\Models\Contracts\Publishable;
use Capell\Core\Models\Contracts\Statusable;
use Capell\Core\Models\Page;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as AuthenticatedUser;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * WordPress-style publish panel for any publishable admin resource. Owns the
 * publish lifecycle (publish now, schedule, revert to draft, schedule/cancel
 * unpublish) plus an optional Active/Inactive status toggle for {@see Statusable}
 * models — acting immediately on the record, independent of the form's Save button.
 *
 * Polymorphic: keyed by a locked (recordClass, recordId), typed to the
 * Publishable/Statusable contracts rather than Page, so every resource editor
 * (Page, Widget, Section, Article, Event, …) drops in the same component. State
 * is recomputed fresh each render via {@see ResolvePublishPanelViewAction}; after
 * any action the panel busts its computed cache and dispatches `refresh-alerts`
 * + `$refresh` so the parent editor updates in lockstep.
 */
final class PublishStatusPanel extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** @var class-string<Model> */
    #[Locked]
    public string $recordClass;

    #[Locked]
    public int $recordId;

    /**
     * @param  class-string<Model>  $recordClass
     */
    public function mount(string $recordClass, int $recordId): void
    {
        $this->recordClass = $recordClass;
        $this->recordId = $recordId;
    }

    #[Computed]
    public function viewData(): PublishPanelViewData
    {
        return ResolvePublishPanelViewAction::run($this->record());
    }

    #[Computed]
    public function readiness(): PublishReadinessData
    {
        $record = $this->record();

        throw_unless($record instanceof Publishable, InvalidArgumentException::class, 'Publish readiness requires a publishable record.');

        return BuildPublishReadinessAction::run($record);
    }

    /**
     * Extender slots — only Page carries the `PagePublishStateData` contract the
     * `PublishPanelExtender` API expects (workspace/preview info from add-ons), so
     * non-Page records render no extensions.
     *
     * @return array<int, View|string>
     */
    #[Computed]
    public function extensions(): array
    {
        $record = $this->record();

        if (! $record instanceof Page) {
            return [];
        }

        $state = ResolvePagePublishStateAction::run($record);

        return collect(app()->tagged(PublishPanelExtender::TAG))
            ->map(fn (PublishPanelExtender $extender): View|string|null => $extender->extendPanel($state))
            ->filter()
            ->values()
            ->all();
    }

    public function publishNowAction(): Action
    {
        return Action::make('publishNow')
            ->label(__('capell-admin::publish_panel.publish_now'))
            ->icon('heroicon-m-rocket-launch')
            ->color('primary')
            ->button()
            ->extraAttributes(['class' => 'whitespace-nowrap'])
            ->visible(fn (): bool => $this->canEdit() && $this->viewData()->isPublishable() && ! $this->viewData()->isLive())
            ->action(function (): void {
                $this->perform(
                    fn (Model&Publishable $record, AuthenticatedUser $actor): PublicationTransitionResultData => PublishRecordAction::run($record, $actor),
                    'published_notification',
                );
            });
    }

    public function schedulePublishAction(): Action
    {
        return Action::make('schedulePublish')
            ->label(fn (): string => $this->viewData()->isScheduled()
                ? __('capell-admin::publish_panel.reschedule')
                : __('capell-admin::publish_panel.schedule'))
            ->icon('heroicon-m-calendar')
            ->link()
            ->visible(fn (): bool => $this->canEdit() && $this->viewData()->isPublishable() && ! $this->viewData()->isExpired())
            ->fillForm(fn (): array => [
                'publish_at' => $this->viewData()->goesLiveAt?->toDateTimeString(),
            ])
            ->schema([
                DateTimePicker::make('publish_at')
                    ->label(__('capell-admin::publish_panel.schedule'))
                    ->seconds(false)
                    ->required()
                    ->minDate(now())
                    ->rules(['after:now']),
            ])
            ->action(function (array $data): void {
                $this->perform(
                    fn (Model&Publishable $record, AuthenticatedUser $actor): PublicationTransitionResultData => ScheduleRecordPublishAction::run(
                        $record,
                        $actor,
                        CarbonImmutable::parse((string) $data['publish_at']),
                    ),
                    'scheduled_publish_notification',
                );
            });
    }

    public function setExpiryAction(): Action
    {
        return Action::make('setExpiry')
            ->label(fn (): string => $this->setExpiryLabel())
            ->tooltip(fn (): string => $this->setExpiryLabel())
            ->icon('heroicon-m-pencil')
            ->iconButton()
            ->visible(fn (): bool => $this->canEdit() && $this->viewData()->isPublishable() && ($this->viewData()->isLive() || $this->viewData()->isScheduled()))
            ->fillForm(fn (): array => [
                'unpublish_at' => $this->viewData()->expiresAt?->toDateTimeString(),
            ])
            ->schema([
                DateTimePicker::make('unpublish_at')
                    ->label(__('capell-admin::publish_panel.expires'))
                    ->seconds(false)
                    ->required()
                    ->minDate(now())
                    ->rules(['after:now']),
            ])
            ->action(function (array $data): void {
                $this->perform(
                    fn (Model&Publishable $record, AuthenticatedUser $actor): PublicationTransitionResultData => ScheduleRecordUnpublishAction::run(
                        $record,
                        $actor,
                        CarbonImmutable::parse((string) $data['unpublish_at']),
                    ),
                    'scheduled_unpublish_notification',
                );
            });
    }

    public function revertToDraftAction(): Action
    {
        return Action::make('revertToDraft')
            ->label(__('capell-admin::publish_panel.switch_to_draft'))
            ->icon('heroicon-m-arrow-uturn-left')
            ->color('gray')
            ->link()
            ->visible(fn (): bool => $this->canEdit() && $this->viewData()->isPublishable() && ! $this->viewData()->isDraft())
            ->requiresConfirmation()
            ->modalHeading(__('capell-admin::publish_panel.switch_to_draft'))
            ->modalDescription(__('capell-admin::publish_panel.switch_to_draft_confirmation'))
            ->action(function (): void {
                $this->perform(
                    fn (Model&Publishable $record, AuthenticatedUser $actor): PublicationTransitionResultData => RevertRecordToDraftAction::run($record, $actor),
                    'reverted_to_draft_notification',
                );
            });
    }

    public function unpublishAction(): Action
    {
        return Action::make('unpublish')
            ->label(__('capell-admin::button.unpublish'))
            ->icon('heroicon-m-eye-slash')
            ->color('gray')
            ->button()
            ->extraAttributes(['class' => 'whitespace-nowrap'])
            ->visible(fn (): bool => $this->canEdit() && $this->viewData()->isPublishable() && $this->viewData()->isLive())
            ->requiresConfirmation()
            ->modalHeading(__('capell-admin::button.unpublish'))
            ->modalDescription(__('capell-admin::message.unpublish_page_confirmation'))
            ->action(function (): void {
                $this->perform(
                    fn (Model&Publishable $record, AuthenticatedUser $actor): PublicationTransitionResultData => UnpublishRecordAction::run($record, $actor),
                    'unpublished_notification',
                );
            });
    }

    public function cancelScheduledUnpublishAction(): Action
    {
        return Action::make('cancelScheduledUnpublish')
            ->label(__('capell-admin::button.cancel_scheduled_unpublish'))
            ->icon('heroicon-m-x-mark')
            ->color('gray')
            ->link()
            ->visible(fn (): bool => $this->canEdit() && $this->viewData()->isPublishable() && $this->viewData()->hasScheduledUnpublish())
            ->requiresConfirmation()
            ->modalHeading(__('capell-admin::button.cancel_scheduled_unpublish'))
            ->modalDescription(__('capell-admin::message.cancel_scheduled_unpublish_confirmation'))
            ->action(function (): void {
                $this->perform(
                    fn (Model&Publishable $record, AuthenticatedUser $actor): PublishVisibilityActionResultData => CancelScheduledRecordUnpublishAction::run($record, $actor),
                    'scheduled_unpublish_cancelled_notification',
                );
            });
    }

    public function toggleStatusAction(): Action
    {
        return Action::make('toggleStatus')
            ->label(fn (): string => $this->viewData()->isEnabled()
                ? __('capell-admin::publish_panel.deactivate')
                : __('capell-admin::publish_panel.activate'))
            ->icon(fn (): string => $this->viewData()->isEnabled() ? 'heroicon-m-pause-circle' : 'heroicon-m-play-circle')
            ->color('gray')
            ->link()
            ->visible(fn (): bool => $this->canEdit() && $this->viewData()->isStatusable())
            ->action(function (): void {
                $record = $this->record();

                if (! $record instanceof Statusable) {
                    return;
                }

                $actor = $this->actor();

                if (! $actor instanceof AuthenticatedUser) {
                    return;
                }

                $this->afterChange(
                    ToggleRecordStatusAction::run($record, $actor),
                    $record->isEnabled() ? 'deactivated_notification' : 'activated_notification',
                );
            });
    }

    public function render(): View
    {
        return view('capell-admin::livewire.publish-status-panel');
    }

    /**
     * Whether the current user may run any publish action — drives whether the
     * footer action bar and inline reveal links render at all.
     */
    public function canManage(): bool
    {
        return $this->canEdit();
    }

    private function record(): Model
    {
        /** @var class-string<Model> $class */
        $class = $this->recordClass;

        $record = $class::query()->findOrFail($this->recordId);

        if (! $record instanceof Publishable && ! $record instanceof Statusable) {
            throw new InvalidArgumentException(sprintf('[%s] is neither publishable nor statusable.', $class));
        }

        return $record;
    }

    private function setExpiryLabel(): string
    {
        return $this->viewData()->hasScheduledUnpublish()
            ? __('capell-admin::publish_panel.edit')
            : __('capell-admin::publish_panel.set');
    }

    private function actor(): ?AuthenticatedUser
    {
        $user = auth()->user();

        return $user instanceof AuthenticatedUser ? $user : null;
    }

    private function canEdit(): bool
    {
        $user = $this->actor();

        return $user instanceof AuthenticatedUser && Gate::forUser($user)->allows('update', $this->record());
    }

    /**
     * Resolve the actor, run the given publish mutation against the record, and —
     * only when it changed — refresh.
     *
     * @param  callable(Model&Publishable, AuthenticatedUser): (PublicationTransitionResultData|PublishVisibilityActionResultData)  $runner
     */
    private function perform(callable $runner, string $messageKey): void
    {
        $actor = $this->actor();

        if (! $actor instanceof AuthenticatedUser) {
            return;
        }

        $record = $this->record();

        if (! $record instanceof Publishable) {
            return;
        }

        $this->afterChange($runner($record, $actor), $messageKey);
    }

    private function afterChange(
        PublicationTransitionResultData|PublishVisibilityActionResultData $result,
        string $messageKey,
    ): void {
        $changed = $result instanceof PublicationTransitionResultData
            ? $result->changed()
            : $result->changed;

        if (! $changed) {
            return;
        }

        unset($this->viewData, $this->extensions);

        Notification::make()
            ->title(__('capell-admin::message.' . $messageKey))
            ->success()
            ->send();

        $this->dispatch('refresh-alerts');
        $this->dispatch('$refresh');
    }
}
