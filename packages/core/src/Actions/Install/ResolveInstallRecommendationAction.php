<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Data\Install\InstallRecommendationData;
use Capell\Core\Enums\InstallRecommendationAction;
use Capell\Core\Support\Install\InstallRecommendationRepository;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolveInstallRecommendationAction
{
    use AsObject;

    public function __construct(private readonly InstallRecommendationRepository $recommendations) {}

    /**
     * @param  list<string>  $customPackages
     * @return list<string>
     */
    public function handle(
        InstallRecommendationAction $action,
        ?string $key = null,
        array $customPackages = [],
    ): array {
        if ($action === InstallRecommendationAction::Skip) {
            return [];
        }

        if ($action === InstallRecommendationAction::Custom) {
            return $this->normaliseCustomPackages($customPackages);
        }

        return $this->recommendationPackages($key);
    }

    /** @return list<string> */
    private function recommendationPackages(?string $key): array
    {
        $recommendation = $this->recommendations->find($key);
        if (! $recommendation instanceof InstallRecommendationData) {
            throw new InvalidArgumentException('Select a valid Capell install recommendation.');
        }

        return $recommendation->packages;
    }

    /**
     * @param  list<string>  $packages
     * @return list<string>
     */
    private function normaliseCustomPackages(array $packages): array
    {
        $normalised = [];
        foreach ($packages as $package) {
            if (! is_string($package) || trim($package) === '') {
                continue;
            }

            $package = trim($package);
            if (! in_array($package, $normalised, true)) {
                $normalised[] = $package;
            }
        }

        return $normalised;
    }
}
