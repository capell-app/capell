<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Frontend\Data\RobotsDirectiveData;

interface RobotsDirectiveContributor
{
    public const string TAG = 'capell-frontend.robots-directive-contributor';

    /** @return list<RobotsDirectiveData> */
    public function directives(): array;
}
