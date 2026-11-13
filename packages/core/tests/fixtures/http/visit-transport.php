<?php

declare(strict_types=1);

// Each probe runs in its own PHP process: native-function substitutes must never
// affect another test's resolver or transport capability checks.

namespace Capell\Core\Support\Http {
    function defined(string $name): bool
    {
        return $name === 'CURLOPT_RESOLVE' && ($GLOBALS['visitTransportMode'] ?? '') === 'no-pin'
            ? false
            : \defined($name);
    }

    /** @return list<string>|false */
    function gethostbynamel(string $hostname): array|false
    {
        return $hostname === 'ipv6-only.example.test' ? false : \gethostbynamel($hostname);
    }

    /** @return list<array<string, mixed>>|false */
    function dns_get_record(string $hostname, int $type): array|false
    {
        if ($hostname !== 'ipv6-only.example.test') {
            return \dns_get_record($hostname, $type);
        }

        throw_if($type !== DNS_AAAA, \LogicException::class, 'The IPv6-only host requires an AAAA lookup.');

        return [['ipv6' => ($GLOBALS['visitTransportMode'] ?? '') === 'private-ipv6' ? 'fd00::1' : '2606:2800:220:1:248:1893:25c8:1946']];
    }
}

namespace {
    use Capell\Core\Actions\VisitUrlAction;
    use Capell\Core\Exceptions\UrlVisitFailedException;
    use Capell\Core\Models\SiteDomain;
    use Capell\Core\Tests\CoreTestCase;
    use GuzzleHttp\Promise\PromiseInterface;
    use Illuminate\Http\Client\Request;
    use Illuminate\Support\Facades\Http;
    use PHPUnit\TextUI\Configuration\Registry;

    require dirname(__DIR__, 5) . '/vendor/autoload.php';

    Registry::loadFrom($argv[2]);

    $GLOBALS['visitTransportMode'] = $argv[1];
    $probe = new class('probe') extends CoreTestCase
    {
        /** @return array{error: ?string, sent: int, pin: mixed} */
        public function probe(): array
        {
            parent::setUp();

            try {
                SiteDomain::factory()->createOne(['domain' => 'ipv6-only.example.test', 'status' => true]);
                $pin = null;
                Http::fake(function (Request $request, array $options) use (&$pin): PromiseInterface {
                    $pin = $options['curl'][CURLOPT_RESOLVE] ?? null;

                    return Http::response('', 200);
                });
                $error = null;

                try {
                    VisitUrlAction::run('https://ipv6-only.example.test/public');
                } catch (UrlVisitFailedException $exception) {
                    $error = $exception->getMessage();
                }

                return [
                    'error' => $error,
                    'sent' => Http::recorded()->count(),
                    'pin' => $pin,
                ];
            } finally {
                parent::tearDown();
            }
        }
    };

    echo json_encode($probe->probe(), JSON_THROW_ON_ERROR);
}
