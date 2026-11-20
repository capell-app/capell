<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Core\Contracts\Rendering\RenderHookExtensionInterface;

/** @deprecated Use \Capell\Core\Contracts\Rendering\RenderHookExtensionInterface instead. */
class_alias(RenderHookExtensionInterface::class, __NAMESPACE__ . '\\RenderHookExtensionInterface');
