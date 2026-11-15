<?php

declare(strict_types=1);

namespace Acme\AnnouncementBar\Actions;

use Acme\AnnouncementBar\Data\AnnouncementBarData;

final class ResolveAnnouncementBarAction
{
    public static function run(): AnnouncementBarData
    {
        return new AnnouncementBarData(enabled: false, message: '');
    }
}
