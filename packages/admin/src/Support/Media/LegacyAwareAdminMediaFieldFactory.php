<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Media;

use Capell\Admin\Contracts\Media\AdminMediaFieldFactory;
use Capell\Core\Contracts\Media\MediaFieldFactory;
use Filament\Forms\Components\Field;
use Illuminate\Contracts\Foundation\Application;

/**
 * Compatibility edge for packages that still rebind Core's 1.x media field
 * contract. First-party Admin schemas continue to resolve the Admin contract.
 */
final class LegacyAwareAdminMediaFieldFactory implements AdminMediaFieldFactory
{
    public function __construct(
        private readonly Application $application,
        private readonly AdminSpatieMediaFieldFactory $default,
    ) {}

    public function make(string $name): Field
    {
        $binding = $this->application->getBindings()[MediaFieldFactory::class]['concrete'] ?? null;

        if ($binding !== LegacyAdminMediaFieldFactoryAdapter::class) {
            $legacy = $this->application->make(MediaFieldFactory::class);
            if (! $legacy instanceof LegacyAdminMediaFieldFactoryAdapter) {
                return $legacy->make($name);
            }
        }

        return $this->default->make($name);
    }
}
