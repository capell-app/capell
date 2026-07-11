<?php

declare(strict_types=1);

namespace Capell\Admin\Data;

use Capell\Admin\Enums\AlertTypeEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Spatie\LaravelData\Data;

class MessageData extends Data
{
    /**
     * @param  Action|ActionGroup|array<int, Action|ActionGroup>|null  $action
     */
    public function __construct(
        public string $title = '',
        public string $message = '',
        public AlertTypeEnum $type = AlertTypeEnum::Info,
        public ?string $icon = null,
        public Action|ActionGroup|array|null $action = null,
    ) {}
}
