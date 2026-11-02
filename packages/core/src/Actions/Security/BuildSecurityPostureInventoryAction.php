<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Security;

use Capell\Core\Contracts\Security\SecurityAdvisorySource;
use Capell\Core\Data\Security\SecurityPostureInventoryData;
use LogicException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Collect the facts every check and analyser reads: installed packages with
 * versions and policies, posture flags (never secret values) and matched
 * advisories from every tagged {@see SecurityAdvisorySource}.
 */
final class BuildSecurityPostureInventoryAction
{
    use AsFake;
    use AsObject;

    public function handle(): SecurityPostureInventoryData
    {
        throw new LogicException('BuildSecurityPostureInventoryAction is not implemented yet.');
    }
}
