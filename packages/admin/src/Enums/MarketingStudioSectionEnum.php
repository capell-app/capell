<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

enum MarketingStudioSectionEnum: string
{
    case WorkQueue = 'work_queue';
    case Campaigns = 'campaigns';
    case Audience = 'audience';
    case Forms = 'forms';
    case Performance = 'performance';
    case Advanced = 'advanced';

    public function label(): string
    {
        return match ($this) {
            self::WorkQueue => __('capell-admin::marketing-studio.section_work_queue'),
            self::Campaigns => __('capell-admin::marketing-studio.section_campaigns'),
            self::Audience => __('capell-admin::marketing-studio.section_audience'),
            self::Forms => __('capell-admin::marketing-studio.section_forms'),
            self::Performance => __('capell-admin::marketing-studio.section_performance'),
            self::Advanced => __('capell-admin::marketing-studio.section_advanced'),
        };
    }

    public function caseOrdinal(): int
    {
        return match ($this) {
            self::WorkQueue => 10,
            self::Campaigns => 20,
            self::Audience => 30,
            self::Forms => 40,
            self::Performance => 50,
            self::Advanced => 60,
        };
    }
}
