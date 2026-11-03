<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Extensions\ReleaseExtensionQuarantineAction;
use Capell\Core\Exceptions\ExtensionVerificationFailedException;
use Capell\Core\Models\ExtensionQuarantine;
use Illuminate\Console\Command;

final class ReleaseExtensionQuarantineCommand extends Command
{
    protected $signature = 'capell:extensions:release-quarantine {package : Composer name of the quarantined package}';

    protected $description = 'Release a quarantined extension after it passes verification.';

    public function handle(): int
    {
        $package = (string) $this->argument('package');
        if (! ExtensionQuarantine::isQuarantined($package)) {
            $this->warn((string) __('capell-core::extensions.quarantine.not_quarantined', ['package' => $package]));

            return self::SUCCESS;
        }

        try {
            ReleaseExtensionQuarantineAction::run($package);
        } catch (ExtensionVerificationFailedException $extensionVerificationFailedException) {
            $this->error((string) __('capell-core::extensions.quarantine.release_refused', ['package' => $package, 'reason' => $extensionVerificationFailedException->getMessage()]));

            return self::FAILURE;
        }

        $this->info((string) __('capell-core::extensions.quarantine.released', ['package' => $package]));

        return self::SUCCESS;
    }
}
