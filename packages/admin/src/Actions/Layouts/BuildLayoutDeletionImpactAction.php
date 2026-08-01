<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Layouts;

use Capell\Admin\Data\RecordDeletionImpactData;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Layout;
use Filament\Resources\Resource;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildLayoutDeletionImpactAction
{
    use AsFake;
    use AsObject;

    public function handle(Layout $layout): RecordDeletionImpactData
    {
        $pagesCount = $this->pagesCount($layout);

        return new RecordDeletionImpactData(
            knownReferenceCount: $pagesCount,
            // The aggregate includes every registered page variation within the current actor's site scope.
            authoritative: true,
            noReferencesLabel: (string) __('capell-admin::generic.deletion_impact_unused'),
            affectedLabel: $pagesCount > 0
                ? (string) trans_choice('capell-admin::generic.deletion_impact_pages', $pagesCount, ['count' => $pagesCount])
                : null,
            referencesUrl: $this->pagesUrl($layout, $pagesCount),
        );
    }

    /** @param iterable<Layout> $layouts */
    public function handleMany(iterable $layouts): RecordDeletionImpactData
    {
        $knownReferenceCount = 0;

        foreach ($layouts as $layout) {
            $knownReferenceCount += $this->pagesCount($layout);
        }

        return new RecordDeletionImpactData(
            knownReferenceCount: $knownReferenceCount,
            authoritative: true,
            noReferencesLabel: (string) __('capell-admin::generic.deletion_impact_unused'),
            affectedLabel: $knownReferenceCount > 0
                ? (string) trans_choice('capell-admin::generic.deletion_impact_pages', $knownReferenceCount, ['count' => $knownReferenceCount])
                : null,
        );
    }

    private function pagesCount(Layout $layout): int
    {
        $pagesCount = $layout->getAttributes()['pages_count'] ?? null;

        return is_numeric($pagesCount)
            ? (int) $pagesCount
            : ResolveLayoutUsageAction::run($layout);
    }

    private function pagesUrl(Layout $layout, int $pagesCount): ?string
    {
        if ($pagesCount === 0) {
            return null;
        }

        $variations = array_values(CapellCore::getPageVariations());
        if (count($variations) !== 1) {
            return null;
        }

        $variation = $variations[0];

        /** @var class-string<resource> $resource */
        $resource = AdminSurfaceLookup::resource(ResourceEnum::Page, $variation->resourceName);

        return $resource::getUrl('index', [
            'filters[layout_id][value]' => $layout->getKey(),
            'filters[system_pages][value]' => '1',
        ]);
    }
}
