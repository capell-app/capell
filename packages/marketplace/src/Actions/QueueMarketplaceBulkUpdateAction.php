<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\MarketplaceBulkUpdateResultData;
use Capell\Marketplace\Data\MarketplaceInstallActorData;
use Capell\Marketplace\Enums\MarketplaceInstallSource;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Queue an update for each of several extensions, and report honestly on the
 * ones that were skipped.
 *
 * There is no batching or fan-out here on purpose: each attempt is queued
 * individually and the jobs serialise themselves on the existing global Composer
 * lock, because two Composer processes rewriting the same composer.json is the
 * worst outcome this system has and no amount of throughput is worth it.
 *
 * One package failing to queue never stops the rest. A bulk action that aborts
 * half way leaves the operator with no idea which half happened, so every
 * refusal is collected and reported alongside what did get queued.
 */
final class QueueMarketplaceBulkUpdateAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  list<string>  $composerNames
     */
    public function handle(
        array $composerNames,
        MarketplaceInstallActorData $actor,
        MarketplaceInstallSource $source = MarketplaceInstallSource::TableHelper,
    ): MarketplaceBulkUpdateResultData {
        $queued = [];
        $skipped = [];

        foreach (array_values(array_unique($composerNames)) as $composerName) {
            $composerName = trim($composerName);

            if ($composerName === '') {
                continue;
            }

            try {
                $attempt = UpdateMarketplaceExtensionAction::run(
                    composerName: $composerName,
                    actor: $actor,
                    source: $source,
                );

                $queued[$composerName] = (int) $attempt->getKey();
            } catch (ValidationException $validationException) {
                $skipped[$composerName] = $this->firstMessage($validationException);
            } catch (Throwable $throwable) {
                report($throwable);
                $skipped[$composerName] = $throwable->getMessage();
            }
        }

        return new MarketplaceBulkUpdateResultData(
            requestedCount: count(array_unique($composerNames)),
            queuedAttemptIds: $queued,
            skipped: $skipped,
        );
    }

    private function firstMessage(ValidationException $validationException): string
    {
        foreach ($validationException->errors() as $messages) {
            foreach ($messages as $message) {
                return (string) $message;
            }
        }

        return $validationException->getMessage();
    }
}
