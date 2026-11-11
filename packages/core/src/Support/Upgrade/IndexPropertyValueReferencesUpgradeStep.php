<?php

declare(strict_types=1);

namespace Capell\Core\Support\Upgrade;

use Capell\Core\Actions\ContentGraph\SyncPropertyReferenceGraphEdgesAction;
use Capell\Core\Data\UpgradeContext;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\Term;
use Capell\Core\Models\TermPropertyValue;
use Illuminate\Database\Eloquent\Builder;
use Override;

/**
 * Indexes the references existing property values already hold, so delete-impact previews list them
 * before each value is next saved. Only property-reference edges are written; the owners' other edges
 * are left as they are, so no extension extractor runs during the upgrade.
 */
final class IndexPropertyValueReferencesUpgradeStep extends AbstractUpgradeStep
{
    public function id(): string
    {
        return 'core.index-property-value-references';
    }

    public function label(): string
    {
        return (string) __('capell-core::properties.upgrade.index_references');
    }

    /** Nothing to index until a property value holds a reference; values written later index themselves. */
    #[Override]
    public function shouldRun(UpgradeContext $context): bool
    {
        if ($this->referencingPageValues()->exists()) {
            return true;
        }

        return $this->referencingTermValues()->exists();
    }

    public function run(UpgradeContext $context): bool
    {
        foreach (Page::query()->withTrashed()->whereIn('id', $this->referencingPageValues()->select('page_id'))->lazyById() as $page) {
            SyncPropertyReferenceGraphEdgesAction::run($page);
        }

        foreach (Term::query()->whereIn('id', $this->referencingTermValues()->select('term_id'))->lazyById() as $term) {
            SyncPropertyReferenceGraphEdgesAction::run($term);
        }

        return true;
    }

    /** @return Builder<PagePropertyValue> */
    private function referencingPageValues(): Builder
    {
        return PagePropertyValue::query()->where(fn (Builder $query): Builder => $query
            ->whereNotNull('media_id')
            ->orWhereNotNull('referenced_page_id')
            ->orWhereNotNull('term_id'));
    }

    /** @return Builder<TermPropertyValue> */
    private function referencingTermValues(): Builder
    {
        return TermPropertyValue::query()->where(fn (Builder $query): Builder => $query
            ->whereNotNull('media_id')
            ->orWhereNotNull('referenced_page_id')
            ->orWhereNotNull('referenced_term_id'));
    }
}
