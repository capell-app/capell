<?php

declare(strict_types=1);

use Capell\Core\Actions\Publishing\BuildPublicationLocaleStatusAction;
use Capell\Core\Data\Publishing\PublicationLocaleStatusContextData;
use Capell\Core\Data\Publishing\PublicationLocaleStatusData;
use Capell\Core\Enums\PublishStatusEnum;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Publishing\PublishSentinel;
use Carbon\CarbonImmutable;

it('projects every publication lifecycle state at an explicit time', function (): void {
    $now = CarbonImmutable::parse('2026-08-10 12:00:00');
    $site = publicationLocaleStatusSite(7);
    $language = publicationLocaleStatusLanguage(11, 'en_GB');
    $action = new BuildPublicationLocaleStatusAction;

    $states = [
        [PublishVisibilityStateEnum::draft, ['visible_from' => PublishSentinel::draftValue($now)]],
        [PublishVisibilityStateEnum::scheduled, ['visible_from' => $now->addHour()]],
        [PublishVisibilityStateEnum::published, ['visible_from' => $now->subHour()]],
        [PublishVisibilityStateEnum::expired, ['visible_from' => $now->subHour(), 'visible_until' => $now]],
        [PublishVisibilityStateEnum::deleted, ['visible_from' => $now->subHour(), 'deleted_at' => $now]],
    ];

    foreach ($states as [$expectedState, $attributes]) {
        $result = $action->handle(new PublicationLocaleStatusContextData(
            record: publicationLocaleStatusPage(['id' => 13, ...$attributes]),
            site: $site,
            language: $language,
            now: $now,
        ));

        expect($result->visibilityState)->toBe($expectedState)
            ->and($result->evaluatedAt)->toBe($now);
    }
});

it('keeps site and language identity isolated in the projection', function (): void {
    $now = CarbonImmutable::parse('2026-08-10 12:00:00');
    $page = publicationLocaleStatusPage(['id' => 13, 'visible_from' => $now->subHour()]);
    $action = new BuildPublicationLocaleStatusAction;

    $english = $action->handle(new PublicationLocaleStatusContextData(
        record: $page,
        site: publicationLocaleStatusSite(7),
        language: publicationLocaleStatusLanguage(11, 'en_GB'),
        now: $now,
    ));
    $welsh = $action->handle(new PublicationLocaleStatusContextData(
        record: $page,
        site: publicationLocaleStatusSite(19),
        language: publicationLocaleStatusLanguage(23, 'cy_GB'),
        now: $now,
    ));

    expect($english)->toBeInstanceOf(PublicationLocaleStatusData::class)
        ->and($english->recordId)->toBe(13)
        ->and($english->recordType)->toBe($page->getMorphClass())
        ->and($english->siteId)->toBe(7)
        ->and($english->languageId)->toBe(11)
        ->and($english->languageCode)->toBe('en_GB')
        ->and($welsh->siteId)->toBe(19)
        ->and($welsh->languageId)->toBe(23)
        ->and($welsh->languageCode)->toBe('cy_GB')
        ->and($welsh->visibilityState)->toBe(PublishVisibilityStateEnum::published);
});

it('does not collapse the canonical visibility state to the legacy publish status', function (): void {
    $now = CarbonImmutable::parse('2026-08-10 12:00:00');
    $result = (new BuildPublicationLocaleStatusAction)->handle(new PublicationLocaleStatusContextData(
        record: publicationLocaleStatusPage(['visible_from' => $now->addHour()]),
        site: publicationLocaleStatusSite(7),
        language: publicationLocaleStatusLanguage(11, 'en_GB'),
        now: $now,
    ));

    expect($result->visibilityState)->toBe(PublishVisibilityStateEnum::scheduled)
        ->and(PublishStatusEnum::fromVisibilityState($result->visibilityState))->toBe(PublishStatusEnum::pending);
});

/** @param array<string, mixed> $attributes */
function publicationLocaleStatusPage(array $attributes): Page
{
    $page = new Page;
    $page->setRawAttributes($attributes);

    return $page;
}

function publicationLocaleStatusSite(int $id): Site
{
    $site = new Site;
    $site->setRawAttributes(['id' => $id]);

    return $site;
}

function publicationLocaleStatusLanguage(int $id, string $code): Language
{
    $language = new Language;
    $language->setRawAttributes(['id' => $id, 'code' => $code]);

    return $language;
}
