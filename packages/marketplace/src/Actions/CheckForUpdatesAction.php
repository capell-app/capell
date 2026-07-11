<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Lorisleiva\Actions\Concerns\AsAction;

final class CheckForUpdatesAction
{
    use AsAction;

    private ?string $failureMessage = null;

    public function handle(): bool
    {
        $this->failureMessage = null;

        $result = PhoneHomeAction::run();

        $this->failureMessage = PhoneHomeAction::lastFailureMessage();

        return $result;
    }

    public function failureMessage(): ?string
    {
        return $this->failureMessage;
    }
}
