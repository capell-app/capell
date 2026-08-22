<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Media;

use Capell\Admin\Contracts\Media\AdminMediaFieldFactory;
use Capell\Core\Contracts\Media\MediaFieldFactory;
use Filament\Forms\Components\Field;

/**
 * @deprecated 1.x compatibility adapter for packages resolving Core's old
 *             Filament-facing MediaFieldFactory contract.
 */
final class LegacyAdminMediaFieldFactoryAdapter implements MediaFieldFactory
{
    public function __construct(private readonly AdminMediaFieldFactory $factory) {}

    public function make(string $name): Field
    {
        return $this->factory->make($name);
    }
}
