<?php

declare(strict_types=1);

namespace Capell\Tests\Fixtures;

use Livewire\Component;
use Override;

/** A host Livewire component outside the admin source directories. */
final class ApplicationPageComponent extends Component
{
    #[Override]
    public function __invoke(): string
    {
        return 'application Livewire page served';
    }
}
