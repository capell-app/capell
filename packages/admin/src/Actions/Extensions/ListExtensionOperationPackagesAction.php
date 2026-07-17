<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Extensions;

use Capell\Admin\Data\Extensions\ExtensionOperationPackageData;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ListExtensionOperationPackagesAction
{
    use AsFake;
    use AsObject;

    /** @return list<ExtensionOperationPackageData> */
    public function handle(?string $search = null, ?string $tab = null, ?string $productGroup = null): array
    {
        return FilterExtensionOperationPackagesAction::run(
            packages: BuildExtensionOperationsSummaryAction::run()->packages,
            search: $search,
            tab: $tab,
            productGroup: $productGroup,
        );
    }
}
