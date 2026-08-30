<?php

declare(strict_types=1);

namespace Capell\Admin\Data;

use Capell\Admin\Contracts\AdminZoneContribution;
use Capell\Admin\Enums\AdminZone;
use Capell\Core\Support\Extensions\ExtensionPosition;
use Closure;
use Illuminate\Support\Facades\Gate;
use LogicException;

final readonly class AdminZoneContributionData implements AdminZoneContribution
{
    /**
     * @param  Closure(AdminZoneContextData): list<mixed>  $resolver
     * @param  Closure(AdminZoneContextData): bool|null  $visibility
     */
    public function __construct(
        private AdminZone $zone,
        private string $key,
        private Closure $resolver,
        private ?ExtensionPosition $position = null,
        private ?string $permission = null,
        private ?Closure $visibility = null,
        private string $owner = 'capell-app/admin',
        private string $source = self::class,
    ) {}

    public function zone(): AdminZone
    {
        return $this->zone;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function position(): ExtensionPosition
    {
        return $this->position ?? ExtensionPosition::priority(0);
    }

    public function isVisible(AdminZoneContextData $context): bool
    {
        if ($this->permission !== null && ! Gate::forUser($context->user)->allows($this->permission)) {
            return false;
        }

        return $this->visibility === null || ($this->visibility)($context);
    }

    /** @return list<mixed> */
    public function resolve(AdminZoneContextData $context): array
    {
        $resolved = ($this->resolver)($context);

        if (! is_array($resolved) || array_is_list($resolved) === false) {
            throw new LogicException(sprintf(
                'Admin zone contribution [%s] from [%s] must resolve to a list.',
                $this->key,
                $this->source,
            ));
        }

        return $resolved;
    }

    public function owner(): string
    {
        return $this->owner;
    }

    public function source(): string
    {
        return $this->source;
    }
}
