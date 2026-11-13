<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use Capell\Core\Contracts\Capabilities\FormCatalogue;
use Capell\Core\Events\Capabilities\CampaignConversionRecorded;
use Capell\Core\Events\Capabilities\FormSubmissionReceived;
use Capell\Core\Events\Capabilities\PublishingWorkspaceTransitioned;
use Capell\Core\Events\Capabilities\SiteAccessRegistrationApproved;

/**
 * Core-owned seams between optional packages. A package that provides one
 * declares it in capell.json under capabilityContracts.provides with the major
 * version it implements; consumers use the typed contract or event below and
 * never look an optional package's classes up by name.
 */
enum CapabilityContract: string
{
    /** Lists the forms a site has, for packages that map form fields. Resolved through CapabilityRegistry. */
    case FormCatalogue = 'form-catalogue';

    /** Dispatches FormSubmissionReceived for every accepted submission. */
    case FormSubmissions = 'form-submissions';

    /** Dispatches SiteAccessRegistrationApproved when a gated-site registration is approved. */
    case SiteAccessRegistrations = 'site-access-registrations';

    /** Dispatches PublishingWorkspaceTransitioned after an editorial state change commits. */
    case PublishingWorkflow = 'publishing-workflow';

    /** Dispatches CampaignConversionRecorded for every recorded conversion. */
    case CampaignConversions = 'campaign-conversions';

    public static function forContract(string $contract): ?self
    {
        return array_find(self::cases(), static fn (self $capability): bool => $capability->contract() === $contract);
    }

    /**
     * The major version Core defines. A breaking change to the contract, event or
     * payload adds a new major version; additive optional fields do not.
     */
    public function version(): int
    {
        return 1;
    }

    /**
     * The interface a provider binds through CapabilityRegistry, for request-style capabilities.
     *
     * @return class-string|null
     */
    public function contract(): ?string
    {
        return match ($this) {
            self::FormCatalogue => FormCatalogue::class,
            default => null,
        };
    }

    /**
     * The Core event a provider dispatches, for signal-style capabilities.
     *
     * @return class-string|null
     */
    public function event(): ?string
    {
        return match ($this) {
            self::FormCatalogue => null,
            self::FormSubmissions => FormSubmissionReceived::class,
            self::SiteAccessRegistrations => SiteAccessRegistrationApproved::class,
            self::PublishingWorkflow => PublishingWorkspaceTransitioned::class,
            self::CampaignConversions => CampaignConversionRecorded::class,
        };
    }
}
