<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Fixtures;

use Capell\Core\Contracts\Publishing\SchedulesPublication;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * A package-style record with its own publication window, standing in for a blog
 * article or an event in the scheduled-publication sweep tests.
 *
 * @property int $id
 * @property int $site_id
 * @property CarbonImmutable|null $visible_from
 * @property CarbonImmutable|null $visible_until
 */
final class ScheduledPublicationEntry extends Model implements SchedulesPublication
{
    public const string TABLE = 'scheduled_publication_entries';

    protected $table = self::TABLE;

    protected $guarded = [];

    #[Override]
    public function scheduledPublicationSurrogateKeys(): array
    {
        return ['scheduled-entry-' . $this->id, 'site-' . $this->site_id];
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'visible_from' => 'immutable_datetime',
            'visible_until' => 'immutable_datetime',
        ];
    }
}
