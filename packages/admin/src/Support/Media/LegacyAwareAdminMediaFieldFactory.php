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
        // @phpstan-ignore-next-line classConstant.deprecatedInterface (This compatibility adapter must inspect the legacy binding.)
        $binding = $this->application->getBindings()[MediaFieldFactory::class]['concrete'] ?? null;

        // @phpstan-ignore-next-line classConstant.deprecatedClass (This comparison identifies the intentional legacy adapter.)
        if ($binding !== LegacyAdminMediaFieldFactoryAdapter::class) {
            // @phpstan-ignore-next-line classConstant.deprecatedInterface (Resolve the legacy contract only for existing 1.x integrations.)
            $legacy = $this->application->make(MediaFieldFactory::class);
            // @phpstan-ignore-next-line instanceof.deprecatedClass (Exclude the compatibility adapter before delegating.)
            if (! $legacy instanceof LegacyAdminMediaFieldFactoryAdapter) {
                return $legacy->make($name);
            }
        }

        return $this->default->make($name);
    }
}
