<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Extensions;

use Spatie\LaravelData\Data;

final class ExtensionHealthAlertData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $packageName,
        public readonly string $severity,
        public readonly string $category,
        public readonly string $title,
        public readonly string $message,
        public readonly ?string $requiredAction = null,
        public readonly bool $runtimeDisabled = false,
        public readonly bool $protectedActionsBlocked = false,
        public readonly ?string $managementUrl = null,
        public readonly ?string $managementLabel = null,
    ) {}

    /** @return array<string, mixed> */
    public function toRecord(): array
    {
        return [
            'id' => $this->id,
            'packageName' => $this->packageName,
            'severity' => $this->severity,
            'category' => $this->category,
            'title' => $this->title,
            'message' => $this->message,
            'requiredAction' => $this->requiredAction,
            'runtimeDisabled' => $this->runtimeDisabled,
            'protectedActionsBlocked' => $this->protectedActionsBlocked,
            'managementUrl' => $this->managementUrl,
            'managementLabel' => $this->managementLabel,
        ];
    }
}
