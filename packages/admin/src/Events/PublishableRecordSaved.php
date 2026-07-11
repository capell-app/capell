<?php

declare(strict_types=1);

namespace Capell\Admin\Events;

use Capell\Core\Models\Contracts\Publishable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after the publish panel changes a non-Page publishable record's publish
 * state (publish, schedule, revert, unpublish, status toggle). Downstream
 * packages (blog, events, layout-builder, …) may listen to record their own
 * history/activity, mirroring what {@see PageSaved} does for
 * Page.
 */
final class PublishableRecordSaved
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly Model&Publishable $record,
        public readonly array $metadata = [],
    ) {}
}
