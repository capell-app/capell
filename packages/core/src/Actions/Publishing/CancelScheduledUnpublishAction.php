<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Publishing;

use Capell\Core\Models\Contracts\Publishable;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsObject;

final class CancelScheduledUnpublishAction
{
    use AsObject;

    public function handle(Model&Publishable $record, CarbonImmutable $now): bool
    {
        $visibleUntil = $record->getAttribute('visible_until');
        $unpublishesAt = $visibleUntil instanceof DateTimeInterface
            ? CarbonImmutable::instance($visibleUntil)
            : null;

        if (! $unpublishesAt?->greaterThan($now)) {
            return false;
        }

        DB::transaction(function () use ($record): void {
            $record->setAttribute('visible_until', null);
            $record->saveOrFail();
        });

        return true;
    }
}
