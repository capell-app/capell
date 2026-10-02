<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Marketplace;

use Capell\Core\Contracts\Marketplace\ExtensionEntitlements;
use Capell\Core\Data\Marketplace\ExtensionLicenceDecisionData;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolveExtensionLicenceDecisionAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly ExtensionEntitlements $entitlements) {}

    public function handle(string $slug, string $action, string $domain): ExtensionLicenceDecisionData
    {
        return $this->entitlements->licenceDecision($slug, $action, $domain);
    }
}
