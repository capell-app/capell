<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Notifications;

use Capell\Admin\Data\Notifications\AdminNotificationGroupData;
use Capell\Admin\Enums\AdminNotificationGroupEnum;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** @extends AbstractKeyedRegistry<AdminNotificationGroupData> */
final class AdminNotificationGroupRegistry extends AbstractKeyedRegistry
{
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

        $this->setItem($resolvedKey, new AdminNotificationGroupData(
            key: $resolvedKey,
            label: $label,
            description: $description,
            defaultRecipients: $defaultRecipients,
        ));

        return $this;
    }

    public function get(AdminNotificationGroupEnum|string $key): ?AdminNotificationGroupData
    {
        $resolvedKey = $key instanceof AdminNotificationGroupEnum ? $key->value : $key;

        return $this->getItem($resolvedKey);
    }

    /**
     * @return array<string, AdminNotificationGroupData>
     */
    public function all(): array
    {
        $groups = $this->allItems();

        uasort(
            $groups,
            static fn (AdminNotificationGroupData $first, AdminNotificationGroupData $second): int => strnatcasecmp($first->label, $second->label),
        );

        return $groups;
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
