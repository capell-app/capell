<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Core\Data\Rendering\RenderHookContext;

/** @deprecated Use \Capell\Core\Data\Rendering\RenderHookContext instead. */
class_alias(RenderHookContext::class, __NAMESPACE__ . '\\RenderHookContext');
