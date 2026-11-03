<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Extensions\HashExtensionContentsAction;
use Illuminate\Console\Command;
use Throwable;

final class HashExtensionContentsCommand extends Command
{
    protected $signature = 'capell:extensions:hash {path}';

    protected $description = 'Calculate the content hash of an extension directory.';

    public function handle(): int
    {
        try {
            $this->line(HashExtensionContentsAction::run((string) $this->argument('path')));
        } catch (Throwable) {
            $this->error((string) __('capell-core::extensions.verification.hash_failed'));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
