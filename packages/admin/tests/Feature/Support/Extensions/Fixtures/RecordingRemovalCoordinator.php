<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Support\Extensions\Fixtures;

use Capell\Admin\Contracts\Extensions\ExtensionRemovalCoordinator;
use Capell\Admin\Data\Extensions\ExtensionRemovalOutcomeData;
use Capell\Admin\Data\Extensions\ExtensionRemovalRequestData;
use Capell\Admin\Enums\Extensions\ExtensionRemovalMode;
use Override;

/**
 * Records what the router handed over, so a test can assert on the request the
 * panel built rather than only on what it decided.
 */
final class RecordingRemovalCoordinator implements ExtensionRemovalCoordinator
{
    /** @var list<ExtensionRemovalRequestData> */
    public array $queuedRequests = [];

    public function __construct(
        private readonly ExtensionRemovalMode $mode,
        private readonly bool $accepted = true,
    ) {}

    #[Override]
    public function modeFor(string $composerName): ExtensionRemovalMode
    {
        unset($composerName);

        return $this->mode;
    }

    #[Override]
    public function manualInstructions(string $composerName, string $extensionName): string
    {
        unset($extensionName);

        return 'Run composer remove ' . $composerName . ' while building the next release.';
    }

    #[Override]
    public function queue(ExtensionRemovalRequestData $request): ExtensionRemovalOutcomeData
    {
        $this->queuedRequests[] = $request;

        return $this->accepted
            ? ExtensionRemovalOutcomeData::accepted('Queued', 'Running in the background.')
            : ExtensionRemovalOutcomeData::refused('Not queued', 'Another operation is active.');
    }
}
