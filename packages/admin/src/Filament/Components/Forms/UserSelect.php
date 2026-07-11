<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User;
use Override;

class UserSelect extends Select
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->searchable()
            ->options(fn (): array => $this->userQuery()->pluck('name', 'id')->all())
            ->getSearchResultsUsing(
                fn (string $search): array => $this->userQuery()
                    ->whereAny(['name', 'email'], 'like', $search . '%')
                    ->pluck('name', 'id')
                    ->all(),
            )
            ->getOptionLabelUsing(
                fn (mixed $value): ?string => $this->userModel()::query()->whereKey($value)->value('name'),
            );
    }

    public static function getDefaultName(): ?string
    {
        return 'user_id';
    }

    public function defaultAuth(): self
    {
        return $this->default(fn (): string|int|null => auth()->id());
    }

    /** @return Builder<User> */
    private function userQuery(): Builder
    {
        return $this->userModel()::query()->limit(10);
    }

    /** @return class-string<User> */
    private function userModel(): string
    {
        return config('auth.providers.users.model');
    }
}
