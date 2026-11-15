<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Dashboard;

use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Filament\Pages\SiteHealthPage;
use Capell\Core\Actions\HostMonitor\AssessHostLoadAction;
use Capell\Core\Data\Diagnostics\HostAssessmentData;
use Capell\Core\Support\HostMonitor\HostMonitorStore;
use Filament\Widgets\Widget;
use Override;

/**
 * A warning above the dashboard while a server is under sustained load. Hidden
 * when every server is healthy, when monitoring is off, and for people who
 * cannot open Site Health, so it never shows a problem the viewer cannot act on.
 */
final class HostLoadAttentionFilamentWidget extends Widget implements CapellFilamentWidgetContract
{
    protected string $view = 'capell-admin::widgets.host-load-attention';

    protected int|string|array $columnSpan = ['default' => 'full'];

    protected static ?int $sort = -1;

    #[Override]
    public static function canView(): bool
    {
        return config('capell.health.host.enabled', true) === true
            && (auth()->user()?->can('View:SiteHealthPage') ?? false)
            && self::assessments() !== [];
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

    /**
     * Every node that needs attention, worst first.
     *
     * @return list<HostAssessmentData>
     */
    public static function assessments(): array
    {
        $assess = resolve(AssessHostLoadAction::class);
        $assessments = [];

        foreach (resolve(HostMonitorStore::class)->nodes() as $node) {
            $assessment = $assess->handle($node);

            if ($assessment->needsAttention()) {
                $assessments[] = $assessment;
            }
        }

        usort($assessments, static fn (HostAssessmentData $a, HostAssessmentData $b): int => $b->status->rank() <=> $a->status->rank());

        return $assessments;
    }

    /** @return array<string, mixed> */
    #[Override]
    protected function getViewData(): array
    {
        return [
            'assessments' => self::assessments(),
            'siteHealthUrl' => SiteHealthPage::getUrl(),
        ];
    }
}
