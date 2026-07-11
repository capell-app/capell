<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Notifications;

use Capell\Admin\Data\Notifications\AdminNotificationGroupData;
use Capell\Admin\Enums\AdminNotificationGroupEnum;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class AdminNotificationGroupRegistry
{
    /** @var array<string, AdminNotificationGroupData> */
    private array $groups = [];

    /**
     * @param  Closure(): Collection<int, Model>  $defaultRecipients
     */
    public function register(
        AdminNotificationGroupEnum|string $key,
        string $label,
        string $description,
        Closure $defaultRecipients,
    ): self {
        $resolvedKey = $key instanceof AdminNotificationGroupEnum ? $key->value : $key;

        throw_if(trim($resolvedKey) === '', InvalidArgumentException::class, 'Notification group key must not be empty.');

        $this->groups[$resolvedKey] = new AdminNotificationGroupData(
            key: $resolvedKey,
            label: $label,
            description: $description,
            defaultRecipients: $defaultRecipients,
        );

        return $this;
    }

    public function get(AdminNotificationGroupEnum|string $key): ?AdminNotificationGroupData
    {
        $resolvedKey = $key instanceof AdminNotificationGroupEnum ? $key->value : $key;

        return $this->groups[$resolvedKey] ?? null;
    }

    /**
     * @return array<string, AdminNotificationGroupData>
     */
    public function all(): array
    {
        uasort(
            $this->groups,
            static fn (AdminNotificationGroupData $first, AdminNotificationGroupData $second): int => strnatcasecmp($first->label, $second->label),
        );

        return $this->groups;
    }

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        return collect($this->all())
            ->mapWithKeys(static fn (AdminNotificationGroupData $group): array => [$group->key => $group->label])
            ->all();
    }
}
