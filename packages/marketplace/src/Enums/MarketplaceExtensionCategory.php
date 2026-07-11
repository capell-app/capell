<?php

declare(strict_types=1);

namespace Capell\Marketplace\Enums;

use Filament\Support\Contracts\HasLabel;

enum MarketplaceExtensionCategory: string implements HasLabel
{
    case SEO = 'seo';
    case Insights = 'insights';
    case Backup = 'backup';
    case Commerce = 'commerce';
    case Content = 'content';
    case Workflow = 'workflow';
    case Security = 'security';
    case Media = 'media';

    public function getLabel(): string
    {
        return (string) __('capell-marketplace::marketplace.categories.' . $this->value);
    }
}
