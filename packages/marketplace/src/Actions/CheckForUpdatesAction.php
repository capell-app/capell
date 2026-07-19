<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class CheckForUpdatesAction
{
    use AsFake;
    use AsObject;

    private ?string $failureMessage = null;

    public function handle(): bool
    {
        $this->failureMessage = null;

        $result = resolve(PhoneHomeAction::class)->result();
        $this->failureMessage = $result->failureMessage;

        return $result->successful;
    }

    public function failureMessage(): ?string
    {
        return $this->failureMessage;
    }
}
