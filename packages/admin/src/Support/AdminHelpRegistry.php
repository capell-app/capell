<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Admin\Data\AdminHelpAnswerData;
use Capell\Admin\Data\AdminHelpEntryData;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;

/**
 * Screen help keyed by admin route name.
 *
 * Packages contribute help for their own screens, or extra answers for an
 * existing screen, from a service provider:
 *
 *     $this->app->afterResolving(AdminHelpRegistry::class, function (AdminHelpRegistry $registry): void {
 *         $registry->register('filament.admin.resources.navigations.index', new AdminHelpEntryData(...));
 *         $registry->addAnswers('filament.admin.resources.pages.index', new AdminHelpAnswerData(...));
 *     });
 *
 * @extends AbstractKeyedRegistry<AdminHelpEntryData>
 */
final class AdminHelpRegistry extends AbstractKeyedRegistry
{
    public function register(string $route, AdminHelpEntryData $entry): void
    {
        $this->setItem($route, $entry);
    }

    /**
     * Append answers to a screen that already has help. Returns false when the
     * route has no entry, so a contributor never creates a titleless drawer.
     */
    public function addAnswers(string $route, AdminHelpAnswerData ...$answers): bool
    {
        $entry = $this->getItem($route);

        if ($entry === null) {
            return false;
        }

        $this->setItem($route, $entry->withAnswers(...$answers));

        return true;
    }

    public function get(string $route): ?AdminHelpEntryData
    {
        return $this->getItem($route);
    }
}
