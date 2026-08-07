<?php

declare(strict_types=1);

namespace Capell\Admin\Enums\Extensions;

/**
 * How this site performs an extension removal.
 *
 * Not a capability ranking and not an error code: all three are supported, and
 * which one applies is a property of the host rather than of the extension.
 * ManualInstructions in particular is a working answer — the removal happens
 * while the next release is built — and the panel's job is to say so rather
 * than to hide the button.
 */
enum ExtensionRemovalMode: string
{
    /**
     * A background worker performs the removal, with a timeline, a health
     * check and a rollback.
     */
    case Queued = 'queued';

    /**
     * This host does not perform runtime Composer writes. The operator is given
     * the commands to run where the release is built.
     */
    case ManualInstructions = 'manual_instructions';

    /**
     * The removal runs inside this HTTP request. The fallback for a site with
     * no marketplace package installed, and the reason the queued path exists.
     */
    case InRequest = 'in_request';
}
