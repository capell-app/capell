<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

enum DashboardEnum: string
{
    case Main = 'main';
    case MarketingStudio = 'marketing_studio';
    case Extensions = 'extensions';
    case NotInstalled = 'not_installed';
    case SystemHealth = 'system_health';
}
