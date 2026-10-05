<?php

declare(strict_types=1);

use Capell\Core\Support\Activity\LogOptions;
use Capell\Core\Support\Activity\VendorLogsActivity;

// These aliases depend on the installed vendor major. Load the real aliases
// before symbol discovery so analysis sees the same types as application code.
throw_if(! class_exists(LogOptions::class) || ! trait_exists(VendorLogsActivity::class), LogicException::class, 'The activity logging compatibility aliases could not be loaded.');
