<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\InboundWebhookEvents\Pages;

use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Resources\InboundWebhookEvents\InboundWebhookEventResource;
use Capell\Admin\Support\AdminSurfaceLookup;
use Filament\Resources\Pages\ListRecords;
use Override;

final class ListInboundWebhookEvents extends ListRecords
{
    /** @return class-string<InboundWebhookEventResource> */
    #[Override]
    public static function getResource(): string
    {
        /** @var class-string<InboundWebhookEventResource> $resource */
        $resource = AdminSurfaceLookup::resource(ResourceEnum::InboundWebhookEvent);

        return $resource;
    }

    #[Override]
    public function getSubheading(): string
    {
        return (string) __('capell-admin::inbound_webhooks.list_subheading');
    }
}
