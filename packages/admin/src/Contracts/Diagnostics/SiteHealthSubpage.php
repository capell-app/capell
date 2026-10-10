<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Diagnostics;

/**
 * Keeps an operational extension page in native navigation beneath Site Health.
 * The page supplies its navigation group, parent and access checks, and declares
 * its own $shouldRegisterNavigation = true property to isolate its state from
 * extension pages whose inherited navigation property is suppressed.
 */
interface SiteHealthSubpage {}
