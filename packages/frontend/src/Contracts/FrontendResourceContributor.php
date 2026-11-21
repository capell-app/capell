<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Core\Contracts\Rendering\FrontendResourceContributor;

/** @deprecated Use \Capell\Core\Contracts\Rendering\FrontendResourceContributor instead. */
class_alias(FrontendResourceContributor::class, __NAMESPACE__ . '\\FrontendResourceContributor');
