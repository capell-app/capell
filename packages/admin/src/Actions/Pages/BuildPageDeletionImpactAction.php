<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Data\Pages\PageRelationshipCountsData;
use Capell\Admin\Data\RecordDeletionImpactData;
use Capell\Admin\Data\RecordRelationshipCountData;
use Capell\Core\Models\Page;
use LogicException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildPageDeletionImpactAction
{
    use AsFake;
    use AsObject;

    public function handle(Page $page): RecordDeletionImpactData
    {
        $counts = PageRelationshipCountsData::fromPage($page);
        $urls = $this->urlsRelationship($counts);

        return new RecordDeletionImpactData(
            knownReferenceCount: $urls->count,
            authoritative: true,
            noReferencesLabel: (string) __('capell-admin::generic.deletion_impact_unused'),
            affectedLabel: $urls->count > 0
                ? trans_choice('capell-admin::generic.deletion_impact_page_urls', $urls->count, ['count' => $urls->count])
                : null,
            referencesUrl: $urls->url,
        );
    }

    /** @param iterable<Page> $pages */
    public function handleMany(iterable $pages): RecordDeletionImpactData
    {
        $knownReferenceCount = 0;

        foreach ($pages as $page) {
            $knownReferenceCount += $this->urlsRelationship(PageRelationshipCountsData::fromPage($page))->count;
        }

        return new RecordDeletionImpactData(
            knownReferenceCount: $knownReferenceCount,
            authoritative: true,
            noReferencesLabel: (string) __('capell-admin::generic.deletion_impact_unused'),
            affectedLabel: $knownReferenceCount > 0
                ? trans_choice('capell-admin::generic.deletion_impact_page_urls', $knownReferenceCount, ['count' => $knownReferenceCount])
                : null,
        );
    }

    private function urlsRelationship(PageRelationshipCountsData $counts): RecordRelationshipCountData
    {
        foreach ($counts->counts() as $relationship) {
            if ($relationship->key === 'urls') {
                return $relationship;
            }
        }

        throw new LogicException('Page URL relationship count is required.');
    }
}
