<?php

declare(strict_types=1);

namespace Capell\Core\Support\Activity;

if (! trait_exists(__NAMESPACE__ . '\\VendorLogsActivity', false)) {
    class_alias(ActivityLogCompat::logsActivityTrait(), __NAMESPACE__ . '\\VendorLogsActivity');
}
