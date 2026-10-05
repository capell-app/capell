<?php

declare(strict_types=1);

namespace Capell\Core\Support\Activity;

// An alias preserves the exact vendor return type required by either logging trait.
class_alias(ActivityLogCompat::logOptionsClass(), __NAMESPACE__ . '\\LogOptions');
