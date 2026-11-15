<?php

declare(strict_types=1);

namespace Capell\Core\Notifications;

use Capell\Core\Data\Diagnostics\HostAssessmentData;
use Capell\Core\Data\Diagnostics\HostSampleData;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class HostMonitorAlertNotification extends Notification
{
    public const string RAISED = 'raised';

    public const string REMINDER = 'reminder';

    public const string RECOVERED = 'recovered';

    /** @param  list<string>  $channels */
    public function __construct(
        private readonly HostAssessmentData $assessment,
        private readonly string $event,
        private readonly array $channels,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function subject(): string
    {
        $replace = [
            'node' => $this->assessment->sample->node ?? 'unknown',
            'status' => $this->assessment->status->label(),
        ];

        return (string) match ($this->event) {
            'reminder' => __('capell-core::health.host_monitor.notification.subject.reminder', $replace),
            'recovered' => __('capell-core::health.host_monitor.notification.subject.recovered', $replace),
            default => __('capell-core::health.host_monitor.notification.subject.raised', $replace),
        };
    }

    /** @return list<string> */
    public function lines(): array
    {
        $lines = [];

        foreach ($this->assessment->reasons as $reason) {
            $lines[] = $reason['message'];
        }

        $sample = $this->assessment->sample;

        if ($sample instanceof HostSampleData && $this->assessment->needsAttention()) {
            if ($sample->topProcesses !== []) {
                $lines[] = (string) __('capell-core::health.host_monitor.notification.busiest', [
                    'processes' => collect($sample->topProcesses)->take(3)->map(
                        static fn (array $process): string => sprintf('%s (%s%%)', $process['command'] !== '' ? $process['command'] : 'pid ' . $process['pid'], number_format($process['cpu'], 0)),
                    )->implode(', '),
                ]);
            }

            if ($sample->slowestSchedulerTasks !== []) {
                $lines[] = (string) __('capell-core::health.host_monitor.notification.slowest', [
                    'tasks' => collect($sample->slowestSchedulerTasks)->map(
                        static fn (array $task): string => sprintf('%s (%ss)', $task['command'], number_format($task['seconds'], 1)),
                    )->implode(', '),
                ]);
            }
        }

        return $lines === [] ? [(string) __('capell-core::health.host_monitor.notification.clear')] : $lines;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject($this->subject());

        foreach ($this->lines() as $line) {
            $message->line($line);
        }

        return $message->line((string) __('capell-core::health.host_monitor.notification.footer'));
    }

    /** @return array{text: string} */
    public function toWebhook(object $notifiable): array
    {
        return ['text' => $this->subject() . "\n" . implode("\n", $this->lines())];
    }
}
