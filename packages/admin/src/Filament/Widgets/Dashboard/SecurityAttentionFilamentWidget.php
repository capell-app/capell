<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Dashboard;

use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Filament\Pages\SecurityPage;
use Capell\Core\Enums\Security\SecurityFindingStatus;
use Capell\Core\Enums\Security\SecurityWeaknessSeverity;
use Capell\Core\Models\SecurityWeaknessFinding;
use Filament\Widgets\Widget;
use Override;

final class SecurityAttentionFilamentWidget extends Widget implements CapellFilamentWidgetContract
{
    protected string $view = 'capell-admin::widgets.security-attention';

    protected int|string|array $columnSpan = ['default' => 'full'];

    protected static ?int $sort = 29;

    #[Override]
    public static function canView(): bool
    {
        return (auth()->user()?->can('View:SecurityPage') ?? false) && self::countFindings() > 0;
    }

    #[Override]
    public static function settingsKey(): string
    {
        return '';
    }

    /** @return list<string> */
    #[Override]
    public static function rolesConfigKeys(): array
    {
        return [];
    }

    public static function countFindings(): int
    {
        return SecurityWeaknessFinding::query()->where('status', SecurityFindingStatus::Open->value)
            ->whereIn('severity', [SecurityWeaknessSeverity::High->value, SecurityWeaknessSeverity::Critical->value])->count();
    }

    /** @return array<string, mixed> */
    #[Override]
    protected function getViewData(): array
    {
        return ['count' => self::countFindings(), 'securityUrl' => SecurityPage::getUrl()];
    }
}
