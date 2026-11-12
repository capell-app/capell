<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Data\Install\InstallPresetData;
use Capell\Core\Data\Install\InstallRecommendationData;
use Capell\Core\Exceptions\UnknownInstallPreset;
use Capell\Core\Support\Install\Cli\InstallSuitePrompter;
use Capell\Core\Support\Install\InstallRecommendationRepository;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Turns `--preset=<name>` into the package set the "What are you building?" question would have
 * produced: the suite's foundation plus its recommended extensions that are free or already
 * downloaded. Recommended extensions that may need a licence are left out, so an unattended
 * install never fails on a download the account cannot make.
 */
final class ResolveInstallPresetAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly InstallRecommendationRepository $suites,
        private readonly InstallSuitePrompter $prompter,
    ) {}

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(
            static fn (InstallRecommendationData $suite): string => $suite->key,
            $this->suites->all(),
        );
    }

    /** @throws UnknownInstallPreset */
    public function handle(string $key): InstallPresetData
    {
        $key = trim($key);
        $suite = collect($this->suites->suites())->first(
            static fn (InstallRecommendationData $candidate): bool => $candidate->key === $key,
        );

        throw_unless($suite instanceof InstallRecommendationData, UnknownInstallPreset::class, $key, $this->keys());

        return new InstallPresetData(
            key: $suite->key,
            label: $suite->label,
            packages: array_values(array_unique([
                ...$this->prompter->foundationPackages($suite->packages),
                ...$suite->preselectedRecommended(),
            ])),
            theme: $suite->theme,
            demo: $suite->demo,
            skipped: $suite->skippedRecommended(),
        );
    }
}
