<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Core\Contracts\Rendering\FrontendResourceSourceData;

/** @deprecated Use \Capell\Core\Contracts\Rendering\FrontendResourceSourceData instead. */
class_alias(FrontendResourceSourceData::class, __NAMESPACE__ . '\\FrontendResourceSourceData');
