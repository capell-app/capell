<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Admin\Enums\RouteOwnershipEnum;
use Illuminate\Routing\Route;

/**
 * The one ownership fact a route carries: decided as the route is added to the router's collection and
 * recorded in its action, so it travels through `route:cache` and is read, never re-derived, wherever a
 * route is selected or served.
 */
final readonly class PanelOwnership
{
    /** The route action attribute holding the ownership. */
    public const string ACTION = 'capell_ownership';

    private function __construct(
        public RouteOwnershipEnum $kind,
        public ?string $panel = null,
        public ?string $domain = null,
        public bool $guests = false,
        public ?string $reason = null,
    ) {}

    /** Registered by the panel's route group, with the domain it was registered on. Sign-in pages and signed links serve guests. */
    public static function panel(string $panel, ?string $domain, bool $guests): self
    {
        return new self(RouteOwnershipEnum::Panel, $panel, $domain, $guests);
    }

    public static function public(): self
    {
        return new self(RouteOwnershipEnum::Public);
    }

    public static function host(): self
    {
        return new self(RouteOwnershipEnum::Host);
    }

    public static function unclassified(): self
    {
        return new self(RouteOwnershipEnum::Unclassified);
    }

    /** @param  string  $reason  The translation key explaining the refusal. */
    public static function refused(string $reason): self
    {
        return new self(RouteOwnershipEnum::Refused, reason: $reason);
    }

    /** The ownership recorded on the route, or null for a route added to a collection that records none. */
    public static function of(Route $route): ?self
    {
        $recorded = $route->getAction(self::ACTION);
        if (! is_array($recorded) || ! is_string($recorded['kind'] ?? null)) {
            return null;
        }

        $kind = RouteOwnershipEnum::tryFrom($recorded['kind']);
        if (! $kind instanceof RouteOwnershipEnum) {
            return null;
        }

        return new self(
            $kind,
            is_string($recorded['panel'] ?? null) ? $recorded['panel'] : null,
            is_string($recorded['domain'] ?? null) ? $recorded['domain'] : null,
            ($recorded['guests'] ?? false) === true,
            is_string($recorded['reason'] ?? null) ? $recorded['reason'] : null,
        );
    }

    /** Record the ownership on the route, as plain values `route:cache` can export. */
    public function recordOn(Route $route): void
    {
        $route->action[self::ACTION] = [
            'kind' => $this->kind->value,
            'panel' => $this->panel,
            'domain' => $this->domain,
            'guests' => $this->guests,
            'reason' => $this->reason,
        ];
    }

    public function ownedBy(string $panel): bool
    {
        return $this->kind === RouteOwnershipEnum::Panel && $this->panel === $panel;
    }
}
