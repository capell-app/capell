<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Data\PublicModelWithdrawalData;
use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Frontend\Settings\FrontendSettings;
use Capell\Frontend\Support\Cache\CacheInvalidationRegistry;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Deprecated;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class InvalidateDueScheduledPublicationCachesAction
{
    use AsFake;
    use AsObject;

    #[Deprecated(message: 'The checkpoint is now persisted in FrontendSettings.')]
    public const string CHECKPOINT_CACHE_KEY = 'capell:frontend:scheduled-publication-invalidation-checkpoint';

    private const int FALLBACK_SCAN_MINUTES = 2;

    private const int CHECKPOINT_OVERLAP_SECONDS = 5;

    /** An idle tick leaves a checkpoint this recent untouched; older ones advance so the scan window stays small. */
    private const int IDLE_CHECKPOINT_MAX_AGE_SECONDS = 600;

    public function handle(?CarbonImmutable $until = null): int
    {
        $until ??= CarbonImmutable::now();
        $settings = resolve(FrontendSettings::class);
        $from = $this->checkpoint(
            $settings->scheduled_publication_invalidation_checkpoint,
            $until,
        );
        $invalidated = 0;

        Page::query()
            ->with(['languages', 'translations'])
            ->where(function (Builder $query) use ($from, $until): void {
                $query
                    ->where(function (Builder $publishQuery) use ($from, $until): void {
                        $publishQuery
                            ->where('visible_from', '>=', $from)
                            ->where('visible_from', '<=', $until);
                    })
                    ->orWhere(function (Builder $unpublishQuery) use ($from, $until): void {
                        $unpublishQuery
                            ->where('visible_until', '>=', $from)
                            ->where('visible_until', '<=', $until);
                    });
            })
            ->chunkById(100, function ($pages) use (&$invalidated, $until): void {
                foreach ($pages as $page) {
                    resolve(CacheInvalidationRegistry::class)->invalidateChangedModel($page);
                    event(new FrontendSurrogateKeysInvalidated(
                        $this->surrogateKeys($page),
                        $this->isPublicAt($page, $until) ? null : PublicModelWithdrawalData::forModel($page),
                    ));
                    $invalidated++;
                }
            });

        if ($invalidated === 0 && $this->storedCheckpointIsRecent($settings->scheduled_publication_invalidation_checkpoint, $until)) {
            // Nothing was due and the stored checkpoint still bounds the next scan, so skip the write and cache flush.
            return 0;
        }

        PersistScheduledPublicationInvalidationCheckpointAction::run(
            $until->toIso8601String(),
        );

        return $invalidated;
    }

    private function storedCheckpointIsRecent(?string $checkpoint, CarbonImmutable $until): bool
    {
        if ($checkpoint === null || ! CarbonImmutable::hasFormat($checkpoint, DateTimeInterface::ATOM)) {
            return false;
        }

        $stored = CarbonImmutable::createFromFormat(DateTimeInterface::ATOM, $checkpoint);

        if (! $stored instanceof CarbonImmutable || $stored->format(DateTimeInterface::ATOM) !== $checkpoint || $stored->greaterThan($until)) {
            return false;
        }

        return $until->getTimestamp() - $stored->getTimestamp() < self::IDLE_CHECKPOINT_MAX_AGE_SECONDS;
    }

    private function checkpoint(?string $checkpoint, CarbonImmutable $until): CarbonImmutable
    {
        if ($checkpoint === null || ! CarbonImmutable::hasFormat($checkpoint, DateTimeInterface::ATOM)) {
            return $until->subMinutes(self::FALLBACK_SCAN_MINUTES);
        }

        $from = CarbonImmutable::createFromFormat(DateTimeInterface::ATOM, $checkpoint);

        if (
            ! $from instanceof CarbonImmutable
            || $from->format(DateTimeInterface::ATOM) !== $checkpoint
        ) {
            return $until->subMinutes(self::FALLBACK_SCAN_MINUTES);
        }

        $from = $from->setTimezone($until->getTimezone());

        if ($from->greaterThan($until)) {
            return $until->subMinutes(self::FALLBACK_SCAN_MINUTES);
        }

        return $from->subSeconds(self::CHECKPOINT_OVERLAP_SECONDS);
    }

    /** A page due in this window that is not public now has expired rather than been published. */
    private function isPublicAt(Page $page, CarbonImmutable $until): bool
    {
        $visibleFrom = $page->visible_from;
        $visibleUntil = $page->visible_until;

        return ($visibleFrom === null || $visibleFrom->lessThanOrEqualTo($until))
            && ($visibleUntil === null || $visibleUntil->greaterThan($until));
    }

    /**
     * @return list<string>
     */
    private function surrogateKeys(Page $page): array
    {
        return [
            'page-' . $page->getKey(),
            'site-' . $page->site_id,
            ...$page->languages
                ->map(fn (Language $language): string => 'lang-' . $language->code)
                ->all(),
        ];
    }
}
