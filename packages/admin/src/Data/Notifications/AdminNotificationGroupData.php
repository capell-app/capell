<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Notifications;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

final class AdminNotificationGroupData extends Data
{
    /**
     * @param  Closure(): Collection<int, Model>  $defaultRecipients
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public Closure $defaultRecipients,
    ) {}
}
