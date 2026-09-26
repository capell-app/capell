<?php

declare(strict_types=1);

namespace Capell\Core\Support\Reporting;

use Capell\Core\Data\Reporting\DispatchResultData;
use Capell\Core\Data\Reporting\OperatorRoutingData;
use Capell\Core\Data\Reporting\RedactedSignalData;
use Capell\Core\Data\Reporting\ReportingOptionsData;
use Capell\Core\Data\Reporting\TransportResultData;
use Capell\Core\Enums\Reporting\DispatchStatus;
use Capell\Core\Enums\Reporting\IncidentStatus;
use Capell\Core\Models\ReportingIncident;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

final readonly class OperatorSignalRouter
{
    private ReportingTransportBoundary $transports;

    public function __construct(Container $container, ?ReportingTransportBoundary $transports = null)
    {
        $this->transports = $transports ?? new ReportingTransportBoundary($container);
    }

    public function report(RedactedSignalData $signal, ReportingOptionsData $options): DispatchResultData
    {
        $routing = $options->routing;
        throw_if(! $routing instanceof OperatorRoutingData, RuntimeException::class, 'Operator routing is unavailable.');
        $incident = $this->claim($signal, $options);
        if ($incident instanceof DispatchResultData) {
            return $incident;
        }

        $deliveries = $incident->deliveries;
        $prefix = $incident->status === IncidentStatus::Escalated ? 'escalation_' : '';
        $failed = false;
        $accepted = false;
        foreach ($routing->channels as $channel) {
            $key = $prefix . $channel;
            if (($deliveries[$key] ?? null) === 'delivered') {
                $accepted = true;

                continue;
            }

            $delivery = match ($channel) {
                'log' => $this->transports->log($signal, $options->logChannel),
                'email' => $this->transports->email($signal, $incident->status === IncidentStatus::Escalated ? $incident->backup : $incident->owner, $incident->status === IncidentStatus::Escalated, $options->cacheStore),
                'health' => TransportResultData::delivered(),
                default => TransportResultData::unavailable(),
            };
            $deliveries[$key] = $delivery->receipt;
            $failed = $failed || ! $delivery->accepted;
            $accepted = $accepted || $delivery->accepted;
        }

        $fallback = false;
        if ($failed) {
            $fallbackKey = $prefix . 'fallback_log';
            $fallback = ($deliveries[$prefix . 'log'] ?? null) === 'delivered' || ($deliveries[$fallbackKey] ?? null) === 'delivered';
            if (! $fallback) {
                $fallback = $this->fallback($signal, $options->logChannel);
                $deliveries[$fallbackKey] = $fallback ? 'delivered' : 'unavailable';
            }
        }

        // A late transport response must not replace the receipt of a newer claim.
        $saved = ReportingIncident::query()->whereKey($incident->fingerprint)->where('claim_token', $incident->claim_token)->update([
            'deliveries' => json_encode($deliveries, JSON_THROW_ON_ERROR),
            'delivery_failed' => $failed,
            'claim_token' => null,
            'claim_until' => 0,
        ]);
        throw_if($saved !== 1, RuntimeException::class, 'Reporting receipt claim has expired.');

        $status = $failed ? ($fallback ? DispatchStatus::Fallback : ($accepted ? DispatchStatus::Partial : DispatchStatus::Failed)) : DispatchStatus::Reported;

        return new DispatchResultData($status, $fallback ? 'log' : 'operator', $failed ? 'transport_unavailable' : null);
    }

    private function claim(RedactedSignalData $signal, ReportingOptionsData $options): ReportingIncident|DispatchResultData
    {
        $routing = $options->routing;
        throw_if(! $routing instanceof OperatorRoutingData, RuntimeException::class, 'Operator routing is unavailable.');

        return new ReportingIncident()->getConnection()->transaction(function () use ($signal, $options, $routing): ReportingIncident|DispatchResultData {
            ReportingIncident::query()->firstOrCreate(['fingerprint' => $signal->fingerprint()], [
                'signal' => $signal->toArray(),
                'status' => IncidentStatus::Open,
                'owner' => $routing->owner,
                'backup' => $routing->backup,
                'health' => in_array('health', $routing->channels, true),
                'deliveries' => [],
                'opened_at' => now(),
            ]);
            $incident = ReportingIncident::query()->whereKey($signal->fingerprint())->lockForUpdate()->firstOrFail();
            if (in_array($incident->status, [IncidentStatus::Acknowledged, IncidentStatus::Resolved], true)) {
                return new DispatchResultData(DispatchStatus::Suppressed, 'operator', $incident->status->value);
            }

            if ($incident->claim_until > now()->getTimestamp()) {
                return new DispatchResultData(DispatchStatus::Suppressed, 'operator', 'delivery_in_progress');
            }

            if ($incident->status === IncidentStatus::Open && in_array('email', $routing->channels, true) && $routing->backup !== null && $incident->opened_at->addSeconds($routing->escalateAfterSeconds)->lessThanOrEqualTo(now())) {
                $incident->status = IncidentStatus::Escalated;
                $incident->escalated_at = CarbonImmutable::now();
                $incident->last_attempt_at = null;
            }

            $health = $incident->health || in_array('health', $routing->channels, true);
            if ($incident->owner !== $routing->owner || $incident->backup !== $routing->backup || $incident->health !== $health) {
                $incident->forceFill([
                    'owner' => $routing->owner,
                    'backup' => $routing->backup,
                    'health' => $health,
                ])->save();
            }

            $prefix = $incident->status === IncidentStatus::Escalated ? 'escalation_' : '';
            $pending = array_filter($routing->channels, fn (string $channel): bool => ($incident->deliveries[$prefix . $channel] ?? null) !== 'delivered');
            if ($pending === []) {
                return new DispatchResultData(DispatchStatus::Suppressed, 'operator', 'already_delivered');
            }

            if ($incident->last_attempt_at?->addSeconds($options->cooldownSeconds)->isFuture()) {
                return new DispatchResultData(DispatchStatus::Suppressed, 'operator', 'cooldown');
            }

            $incident->forceFill([
                'claim_token' => bin2hex(random_bytes(32)),
                'claim_until' => now()->getTimestamp() + 60,
                'last_attempt_at' => now(),
            ])->save();

            return $incident;
        });
    }

    private function fallback(RedactedSignalData $signal, ?string $channel): bool
    {
        return array_any($channel === null ? [null] : [$channel, null], fn (?string $candidate): bool => $this->transports->log($signal, $candidate)->accepted);
    }
}
