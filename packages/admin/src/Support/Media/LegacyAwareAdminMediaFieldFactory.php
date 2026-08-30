<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Media;

use Capell\Admin\Contracts\Media\AdminMediaFieldFactory;
use Filament\Forms\Components\Field;
use Illuminate\Contracts\Foundation\Application;

/**
 * Compatibility edge for packages that still rebind Core's 1.x media field
 * contract. First-party Admin schemas continue to resolve the Admin contract.
 */
final class LegacyAwareAdminMediaFieldFactory implements AdminMediaFieldFactory
{
    private const string LEGACY_MEDIA_FIELD_FACTORY = 'Capell\\Core\\Contracts\\Media\\MediaFieldFactory';

    private const string LEGACY_ADAPTER = 'Capell\\Admin\\Support\\Media\\LegacyAdminMediaFieldFactoryAdapter';

    public function __construct(
        private readonly Application $application,
        private readonly AdminSpatieMediaFieldFactory $default,
    ) {}

    public function make(string $name): Field
    {
        $binding = $this->application->getBindings()[self::LEGACY_MEDIA_FIELD_FACTORY]['concrete'] ?? null;

        if ($binding !== self::LEGACY_ADAPTER) {
            $legacy = $this->application->make(self::LEGACY_MEDIA_FIELD_FACTORY);

            if (! is_a($legacy, self::LEGACY_ADAPTER, true)) {
                return $legacy->make($name);
            }
        }

        return $this->default->make($name);
    }
}
