<?php

declare(strict_types=1);

namespace Capell\Core\Data\Diagnostics;

use Capell\Core\Data\VendorAssetData;
use Spatie\LaravelData\Data;

final class FrontendBuildAssetVerificationResultData extends Data
{
    public function __construct(
        public VendorAssetData $asset,
        public string $buildPath,
        public string $sourceFile,
        public bool $passed,
        public string $message,
        public ?string $manifestPath = null,
        public ?string $compiledFilePath = null,
        public ?string $remediation = null,
    ) {}
}
