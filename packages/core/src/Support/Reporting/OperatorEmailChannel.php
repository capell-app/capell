<?php

declare(strict_types=1);

namespace Capell\Core\Support\Reporting;

use Capell\Core\Data\Reporting\RedactedSignalData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Mail\Message;
use InvalidArgumentException;
use RuntimeException;

final readonly class OperatorEmailChannel
{
    public function __construct(private Container $container) {}

    public function report(RedactedSignalData $signal, ?string $operator, bool $escalated, ?string $cacheStore): string
    {
        $configuration = $this->container->make(Repository::class)->get('capell-reporting');
        throw_unless(is_array($configuration), InvalidArgumentException::class, 'Reporting configuration is unavailable.');
        $email = $configuration['email'] ?? [];
        throw_unless(is_array($email), InvalidArgumentException::class, 'Operator email configuration is invalid.');
        if (($email['enabled'] ?? false) !== true) {
            return 'disabled';
        }

        $operators = $configuration['operators'] ?? [];
        $address = is_array($operators) && $operator !== null ? ($operators[$operator] ?? null) : null;
        $mailer = $email['mailer'] ?? null;
        $limit = $email['max_attempts'] ?? 5;
        $window = $email['window_seconds'] ?? 3600;
        throw_if(! is_string($address) || filter_var($address, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $address) === 1, InvalidArgumentException::class, 'Operator email address is unavailable.');
        throw_if($mailer !== null && (! is_string($mailer) || $mailer === ''), InvalidArgumentException::class, 'Operator mailer is invalid.');
        throw_if(! is_int($limit) || $limit < 1 || $limit > 1000 || ! is_int($window) || $window < 1 || $window > 86400, InvalidArgumentException::class, 'Operator email rate limit is invalid.');

        if (! $this->reserveAttempt($cacheStore, $limit, $window)) {
            return 'rate_limited';
        }

        $sent = $this->container->make(MailFactory::class)->mailer($mailer)->raw(
            $signal->toHuman() . "\n\n" . $signal->toJson(),
            function (Message $message) use ($signal, $address, $escalated): void {
                $message->to($address)->subject(__('capell::reporting.' . ($escalated ? 'escalation_subject' : 'failure_subject'), ['severity' => $signal->severity->value, 'signal' => $signal->name]));
            },
        );

        // A cancelled message event is not accepted delivery.
        return $sent === null ? 'unavailable' : 'delivered';
    }

    private function reserveAttempt(?string $cacheStore, int $limit, int $window): bool
    {
        $cache = $this->container->make(CacheFactory::class)->store($cacheStore);
        $store = $cache->getStore();
        throw_unless($store instanceof LockProvider, RuntimeException::class, 'Operator email requires atomic rate limiting.');
        $lock = $store->lock('capell:reporting:email-quota-lock', 10);
        throw_unless($lock->get(), RuntimeException::class, 'Operator email quota is busy.');

        try {
            $key = 'capell:reporting:email-quota';
            $quota = $cache->get($key, ['attempts' => 0, 'expires_at' => now()->getTimestamp() + $window]);
            throw_if(! is_array($quota) || ! is_int($quota['attempts'] ?? null) || ! is_int($quota['expires_at'] ?? null) || $quota['attempts'] < 0 || $quota['expires_at'] < 1, RuntimeException::class, 'Operator email quota is invalid.');
            if ($quota['expires_at'] <= now()->getTimestamp()) {
                $quota = ['attempts' => 0, 'expires_at' => now()->getTimestamp() + $window];
            }

            if ($quota['attempts'] >= $limit) {
                return false;
            }

            $quota['attempts']++;
            throw_unless($cache->put($key, $quota, CarbonImmutable::createFromTimestamp($quota['expires_at'])), RuntimeException::class, 'Operator email quota could not be reserved.');

            return true;
        } finally {
            $lock->release();
        }
    }
}
