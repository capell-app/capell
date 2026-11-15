<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Pages\Actions;

use Capell\Core\Actions\Ai\ConfirmAiProvenanceAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\AiDisclosure;
use Capell\Core\Models\AiProvenance;
use Capell\Core\Models\Language;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Override;

/**
 * Shows the editor which fields of the page (or any other pageable record, such as a blog article) were written with AI and have not
 * been reviewed, and records their review. Until then the page cannot go public.
 */
class ReviewAiContentHeaderAction extends Action
{
    /** @var array<string, list<array{field: string, language: string|null, disclosure: string, capability: string|null}>> */
    private array $unreviewed = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn (Pageable&Model $record): string => __('capell-admin::ai.review_action', ['count' => count($this->unreviewedFields($record))]))
            ->icon('heroicon-o-sparkles')
            ->color('warning')
            ->visible(fn (Pageable&Model $record): bool => $this->unreviewedFields($record) !== [] && Gate::allows('update', $record))
            ->modalHeading(__('capell-admin::ai.review_heading'))
            ->modalDescription(__('capell-admin::ai.review_description'))
            ->modalContent(function (Pageable&Model $record): View {
                /** @var view-string $view */
                $view = 'capell-admin::filament.actions.review-ai-content';

                return view($view, ['fields' => $this->unreviewedFields($record)]);
            })
            ->modalSubmitActionLabel(__('capell-admin::ai.review_confirm'))
            ->action(function (Pageable&Model $record): void {
                $reviewerId = Auth::id();

                if (! is_int($reviewerId) || Gate::denies('update', $record)) {
                    return;
                }

                ConfirmAiProvenanceAction::run($record, $reviewerId);
                unset($this->unreviewed[$record->getMorphClass() . ':' . $record->getKey()]);

                Notification::make()
                    ->success()
                    ->title(__('capell-admin::ai.review_done'))
                    ->send();
            });
    }

    public static function getDefaultName(): ?string
    {
        return 'review-ai-content';
    }

    /**
     * @param  Pageable<Model>&Model  $record
     * @return list<array{field: string, language: string|null, disclosure: string, capability: string|null}>
     */
    private function unreviewedFields(Pageable&Model $record): array
    {
        $key = $record->getMorphClass() . ':' . $record->getKey();

        if (array_key_exists($key, $this->unreviewed)) {
            return $this->unreviewed[$key];
        }

        $rows = AiProvenance::query()
            ->where('pageable_type', $record->getMorphClass())
            ->where('pageable_id', $record->getKey())
            ->whereNull('confirmed_at')
            ->where('disclosure', '!=', AiDisclosure::None->value)
            ->orderBy('language_id')
            ->orderBy('field')
            ->get();

        if ($rows->isEmpty()) {
            return $this->unreviewed[$key] = [];
        }

        $languages = Language::query()
            ->whereIn('id', $rows->pluck('language_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        $fields = [];

        foreach ($rows as $row) {
            $fields[] = [
                'field' => $row->field,
                'language' => $row->language_id === null ? null : (string) ($languages[$row->language_id] ?? ''),
                'disclosure' => match ($row->disclosure) {
                    AiDisclosure::None => (string) __('capell-admin::ai.disclosure_none'),
                    AiDisclosure::Assisted => (string) __('capell-admin::ai.disclosure_assisted'),
                    AiDisclosure::Generated => (string) __('capell-admin::ai.disclosure_generated'),
                },
                'capability' => $row->capability,
            ];
        }

        return $this->unreviewed[$key] = $fields;
    }
}
