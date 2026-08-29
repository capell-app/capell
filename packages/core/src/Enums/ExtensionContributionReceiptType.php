<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

/**
 * Receipt-only identities for supported registrars without manifest markers.
 *
 * These values deliberately do not widen manifest-v3's contribution vocabulary.
 */
enum ExtensionContributionReceiptType: string
{
    case Subscriber = 'subscriber';
    case MetricCollector = 'metric-collector';
    case CacheDependency = 'cache-dependency';
    case InstallPatch = 'install-patch';
    case ContentGraph = 'content-graph-registration';
    case LinkableContent = 'linkable-content';
    case VendorAssetCondition = 'vendor-asset-condition';
    case Maker = 'maker';
    case AdminEvent = 'admin-event';
    case ReservedFrontendPath = 'reserved-frontend-path';
    case FrontendRouteMiddleware = 'frontend-route-middleware';
    case FrontendRuleCondition = 'frontend-rule-condition';

    public function bucket(): string
    {
        return match ($this) {
            self::Subscriber, self::MetricCollector => 'runtime',
            self::CacheDependency => 'frontend',
            self::InstallPatch => 'install',
            self::ContentGraph,
            self::LinkableContent,
            self::VendorAssetCondition,
            self::Maker => 'runtime',
            self::AdminEvent => 'admin',
            self::ReservedFrontendPath,
            self::FrontendRouteMiddleware,
            self::FrontendRuleCondition => 'frontend',
        };
    }
}
