<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Integration\Actions;

use Illuminate\View\Component;
use Override;

final class ParityBladeComponent extends Component
{
    #[Override]
    public function render(): string
    {
        return '<div>runtime</div>';
    }
}
