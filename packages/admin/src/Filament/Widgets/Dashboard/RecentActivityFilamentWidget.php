<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Dashboard;

use Capell\Admin\Actions\Activity\BuildActivityChangeSetAction;
use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Contracts\DashboardReports\ActivityTrailQueryProvider;
use Capell\Admin\Data\Activity\ActivityChangeSetData;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Capell\Admin\Filament\Resources\Activities\Tables\ActivitiesTable;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Core\Models\Language;
use Capell\Core\Models\Translation;
use Filament\Actions\Action;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Override;
use Spatie\Activitylog\Models\Activity;

final class RecentActivityFilamentWidget extends BaseWidget implements CapellFilamentWidgetContract
{
    use GatedByRoleAndSettings;

    /** @var list<string> */
    protected static array $rolesConfigKeys = ['editor', 'admin', 'super_admin'];

    protected static string $settingsKey = 'recent_activity';

    protected static ?int $sort = 11;

    protected int|string|array $columnSpan = [
        'default' => 'full',
    ];

    #[Override]
    public static function canView(): bool
    {
        return self::canViewCheck() && self::hasActivity();
    }

    #[Override]
    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->activityQuery())
            ->queryStringIdentifier('recent-activity')
            ->heading(__('capell-admin::dashboard.widget_activity_log'))
            ->paginated(false)
            ->searchable(false)
            ->headerActions([
                Action::make('view-all')
                    ->label(__('capell-admin::button.view_all'))
                    ->button()
                    ->color('gray')
                    ->url(AdminSurfaceLookup::resource(ResourceEnum::Activity)::getUrl()),
            ])
            ->columns([
                Stack::make([
                    TextColumn::make('activity_subject')
                        ->label(__('capell-admin::activity.subject'))
                        ->state(fn (Activity $record): string => $this->subjectLabel($record, $this->changeSet($record)))
                        ->size(TextSize::Small)
                        ->wrap(),
                    TextColumn::make('activity_meta')
                        ->label(__('capell-admin::dashboard.activity_change'))
                        ->state(fn (Activity $record): HtmlString => $this->metaSummary($record))
                        ->color('gray')
                        ->size(TextSize::ExtraSmall)
                        ->html()
                        ->wrap(),
                ])
                    ->space(2)
                    ->extraAttributes(['class' => 'min-w-0']),
            ])
            ->recordAction('viewActivity')
            ->recordActions([
                ActivitiesTable::viewDetailsAction()
                    ->hidden(),
            ]);
    }

    private static function hasActivity(): bool
    {
        return resolve(ActivityTrailQueryProvider::class)
            ->build()
            ->exists();
    }

    private function translationString(string $key): string
    {
        $value = __($key);

        return is_string($value) ? $value : $key;
    }

    private function changeSet(Activity $activity): ActivityChangeSetData
    {
        $relation = 'capellActivityChangeSet';
        $cachedChangeSet = $activity->relationLoaded($relation) ? $activity->getRelation($relation) : null;

        if ($cachedChangeSet instanceof ActivityChangeSetData) {
            return $cachedChangeSet;
        }

        $cacheKey = 'capell.activity_change_sets';
        $activityKey = $this->activityCacheKey($activity);
        $cachedChangeSets = request()->attributes->get($cacheKey, []);
        $requestCachedChangeSet = is_array($cachedChangeSets) ? ($cachedChangeSets[$activityKey] ?? null) : null;

        if ($requestCachedChangeSet instanceof ActivityChangeSetData) {
            $activity->setRelation($relation, $requestCachedChangeSet);

            return $requestCachedChangeSet;
        }

        $changeSet = BuildActivityChangeSetAction::run($activity);
        $activity->setRelation($relation, $changeSet);
        $cachedChangeSets = is_array($cachedChangeSets) ? $cachedChangeSets : [];
        $cachedChangeSets[$activityKey] = $changeSet;
        request()->attributes->set($cacheKey, $cachedChangeSets);

        return $changeSet;
    }

    private function activityCacheKey(Activity $activity): string
    {
        $activityKey = $activity->getKey();

        if ($activityKey === null) {
            return 'unsaved:' . spl_object_id($activity);
        }

        return $activity::class . ':' . $activityKey;
    }

    private function eventLabel(Activity $activity): string
    {
        $changeSet = $this->changeSet($activity);
        $event = $changeSet->event;

        if (filled($event)) {
            return Str::of($event)->headline()->toString();
        }

        return Str::of($changeSet->summary)
            ->before('(')
            ->trim()
            ->before(' ')
            ->headline()
            ->toString();
    }

    private function eventColor(Activity $activity): string
    {
        return match (Str::of($this->eventLabel($activity))->lower()->toString()) {
            'created' => 'success',
            'deleted' => 'danger',
            'updated' => 'warning',
            default => 'gray',
        };
    }

    private function eventBadge(Activity $activity): HtmlString
    {
        $eventLabel = $this->eventLabel($activity);
        $eventColor = $this->eventColor($activity);

        $badgeClasses = match ($eventColor) {
            'success' => 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-400/10 dark:text-success-400 dark:ring-success-400/20',
            'danger' => 'bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400 dark:ring-danger-400/20',
            'warning' => 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400 dark:ring-warning-400/20',
            default => 'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10',
        };

        return new HtmlString(sprintf(
            '<span class="inline-flex shrink-0 items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset %s">%s</span>',
            $badgeClasses,
            e($eventLabel),
        ));
    }

    private function metaSummary(Activity $activity): HtmlString
    {
        $changeSet = $this->changeSet($activity);
        $createdAt = $changeSet->occurredAt?->diffForHumans() ?? $this->translationString('capell-admin::generic.unknown');
        $summary = $activity->subject instanceof Translation ? null : $changeSet->summary;

        $summaryText = collect([
            $createdAt,
            $changeSet->actorLabel,
            $summary,
        ])
            ->filter(fn (mixed $value): bool => is_string($value) && filled($value))
            ->join(' · ');

        return new HtmlString(sprintf(
            '<div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">%s<span class="min-w-0 break-words">%s</span></div>',
            $this->eventBadge($activity),
            e($summaryText),
        ));
    }

    private function subjectLabel(Activity $activity, ActivityChangeSetData $changeSet): string
    {
        if ($activity->subject instanceof Translation) {
            return $this->translationSubjectLabel($activity->subject);
        }

        return $changeSet->resource->label
            ?? __('capell-admin::activity.subject_missing');
    }

    private function translationSubjectLabel(Translation $translation): string
    {
        $translatable = $translation->getRelationValue('translatable');
        $title = $translatable instanceof Model ? (
            $translatable->getAttribute('name')
            ?? $translatable->getAttribute('title')
        ) : null;
        $title ??= $translation->title
        ?? $translation->getKey();

        $label = sprintf('%s Translation', $title);
        $languageModel = $translation->getRelationValue('language');
        $language = $languageModel instanceof Language ? $languageModel->name : null;

        return filled($language) ? sprintf('%s (%s)', $label, $language) : $label;
    }

    /**
     * @return Builder<Model>
     */
    private function activityQuery(): Builder
    {
        return resolve(ActivityTrailQueryProvider::class)
            ->build()
            ->with([
                'causer',
                'subject' => function (Relation $subject): void {
                    if (! $subject instanceof MorphTo) {
                        return;
                    }

                    $subject->morphWith([
                        Translation::class => [
                            'language',
                            'translatable',
                        ],
                    ]);
                },
            ])
            ->latest('created_at')
            ->limit(5);
    }
}
