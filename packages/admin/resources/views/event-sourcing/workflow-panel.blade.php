@php
    use Capell\Core\EventSourcing\Enums\PageWorkflowStatus;

    /** @var PageWorkflowStatus $status */
    $colors = [
        'draft' => 'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-200',
        'in_review' => 'bg-warning-100 text-warning-700 dark:bg-warning-900/30 dark:text-warning-300',
        'changes_requested' => 'bg-danger-100 text-danger-700 dark:bg-danger-900/30 dark:text-danger-300',
        'approved' => 'bg-info-100 text-info-700 dark:bg-info-900/30 dark:text-info-300',
        'scheduled' => 'bg-info-100 text-info-700 dark:bg-info-900/30 dark:text-info-300',
        'published' => 'bg-success-100 text-success-700 dark:bg-success-900/30 dark:text-success-300',
        'unpublished' => 'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-200',
        'archived' => 'bg-gray-200 text-gray-600 dark:bg-white/5 dark:text-gray-400',
    ];
    $badgeClass = $colors[$status->value] ?? $colors['draft'];
@endphp

<div class="space-y-1.5 text-sm">
    <div class="flex items-center gap-2">
        <span class="text-gray-500 dark:text-gray-400">
            {{ __('capell-admin::event-sourcing.workflow_status') }}
        </span>
        <span
            class="{{ $badgeClass }} ml-auto inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
        >
            {{ Str::headline($status->value) }}
        </span>
    </div>

    @if ($note)
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $note }}</p>
    @endif
</div>
