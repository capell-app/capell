<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\MarketplaceComposerResultData;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallFailureType;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class ClassifyMarketplaceInstallFailureAction
{
    use AsFake;
    use AsObject;

    public function handle(
        ?MarketplaceInstallFailureStage $stage = null,
        ?Throwable $throwable = null,
        ?MarketplaceComposerResultData $composerResult = null,
        ?string $message = null,
        ?string $deploymentStatus = null,
    ): array {
        $haystack = strtolower(implode("\n", array_filter([
            $message,
            $throwable?->getMessage(),
            $composerResult?->output,
            $composerResult?->errorOutput,
            $deploymentStatus,
        ])));

        $resolvedStage = $stage ?? $this->stageFromText($haystack);
        $type = $this->typeFromText($haystack, $composerResult, $deploymentStatus);

        return [
            'failure_type' => $type,
            'failure_stage' => $resolvedStage,
        ];
    }

    private function stageFromText(string $haystack): MarketplaceInstallFailureStage
    {
        if (str_contains($haystack, 'deployment')) {
            return MarketplaceInstallFailureStage::DeploymentHandoff;
        }

        if (str_contains($haystack, 'discovered') || str_contains($haystack, 'registry')) {
            return MarketplaceInstallFailureStage::PackageDiscovery;
        }

        if (str_contains($haystack, 'lifecycle')) {
            return MarketplaceInstallFailureStage::Lifecycle;
        }

        if (str_contains($haystack, 'queue') || str_contains($haystack, 'attempted too many times')) {
            return MarketplaceInstallFailureStage::Queue;
        }

        return MarketplaceInstallFailureStage::Composer;
    }

    private function typeFromText(
        string $haystack,
        ?MarketplaceComposerResultData $composerResult,
        ?string $deploymentStatus,
    ): MarketplaceInstallFailureType {
        if ($composerResult?->timedOut === true || str_contains($haystack, 'timed out') || str_contains($haystack, 'timeout')) {
            return MarketplaceInstallFailureType::Timeout;
        }

        if (str_contains($haystack, 'php cli') || str_contains($haystack, 'php binary') || str_contains($haystack, 'php-fpm')) {
            return MarketplaceInstallFailureType::PhpBinary;
        }

        if (str_contains($haystack, 'authentication') || str_contains($haystack, 'requires a token') || str_contains($haystack, 'http 401') || str_contains($haystack, 'http 403')) {
            return MarketplaceInstallFailureType::ComposerAuth;
        }

        if (str_contains($haystack, 'could not find a matching version') || str_contains($haystack, 'conflict') || str_contains($haystack, 'your requirements could not be resolved')) {
            return MarketplaceInstallFailureType::ComposerConstraint;
        }

        if (str_contains($haystack, 'could not resolve host') || str_contains($haystack, 'connection refused') || str_contains($haystack, 'network') || str_contains($haystack, 'curl error')) {
            return MarketplaceInstallFailureType::Network;
        }

        if (str_contains($haystack, 'not discovered')) {
            return MarketplaceInstallFailureType::PackageNotDiscovered;
        }

        if (str_contains($haystack, 'cancelled after composer')) {
            return MarketplaceInstallFailureType::CancelledAfterComposer;
        }

        if ($deploymentStatus === 'failed' || str_contains($haystack, 'deployment failed')) {
            return MarketplaceInstallFailureType::DeploymentFailed;
        }

        if ($deploymentStatus === 'unavailable' || str_contains($haystack, 'deployment unavailable') || str_contains($haystack, 'deployments is unavailable')) {
            return MarketplaceInstallFailureType::DeploymentUnavailable;
        }

        if (str_contains($haystack, 'lifecycle')) {
            return MarketplaceInstallFailureType::LifecycleException;
        }

        return MarketplaceInstallFailureType::Unknown;
    }
}
