<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Data\SiteSpec\CapellSiteSpecPageData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\SiteSpec\SiteSpecApplierRegistry;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/**
 * Refuses a SiteSpec the installation cannot carry out, before anything is written.
 */
final class AssertSiteSpecCanBeAppliedAction
{
    use AsFake;
    use AsObject;

    public function handle(CapellSiteSpecData $spec): void
    {
        $this->assertExtensionRequirementsAreInstalled($spec);
        $this->assertReferencesCanBeApplied($spec);
    }

    private function assertExtensionRequirementsAreInstalled(CapellSiteSpecData $spec): void
    {
        $missingExtensions = array_values(array_filter(
            array_unique($spec->extensions),
            static fn (string $extension): bool => ! CapellCore::isPackageInstalled($extension),
        ));

        throw_if($missingExtensions !== [], RuntimeException::class, sprintf(
            'Install the required Capell extension(s) before importing this site spec: %s.',
            implode(', ', $missingExtensions),
        ));
    }

    private function assertReferencesCanBeApplied(CapellSiteSpecData $spec): void
    {
        $pageSlugs = array_map(static fn (CapellSiteSpecPageData $page): string => $page->slug, $spec->pages);

        foreach ($spec->navigations as $navigation) {
            foreach ($navigation->pageSlugs as $pageSlug) {
                throw_unless(in_array($pageSlug, $pageSlugs, true), RuntimeException::class, sprintf(
                    'Navigation [%s] references missing page slug [%s].',
                    $navigation->key,
                    $pageSlug,
                ));
            }
        }

        foreach (array_keys($spec->media->images) as $pageSlug) {
            throw_unless(is_string($pageSlug) && in_array($pageSlug, $pageSlugs, true), RuntimeException::class, sprintf(
                'Site spec media references missing page slug [%s].',
                (string) $pageSlug,
            ));
        }

        if ($spec->navigations !== []) {
            throw_unless(resolve(SiteSpecApplierRegistry::class)->has('navigation'), RuntimeException::class, 'The site spec contains navigation data, but no installed package registered the [navigation] site spec applier.');
        }
    }
}
