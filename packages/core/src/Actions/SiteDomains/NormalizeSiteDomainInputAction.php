<?php

declare(strict_types=1);

namespace Capell\Core\Actions\SiteDomains;

use Capell\Core\Data\SiteDomains\SiteDomainInputData;
use Capell\Core\Support\SiteDomains\SiteDomainAddressing;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static SiteDomainInputData run(string $url, bool $wildcardHost = false)
 */
final class NormalizeSiteDomainInputAction
{
    use AsFake;
    use AsObject;

    public function handle(string $url, bool $wildcardHost = false): SiteDomainInputData
    {
        return SiteDomainAddressing::inputFromUrl($url, $wildcardHost);
    }
}
