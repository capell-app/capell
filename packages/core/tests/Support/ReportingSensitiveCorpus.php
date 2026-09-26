<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

final class ReportingSensitiveCorpus
{
    /** @return array<string, array{string, string}> */
    public static function cases(): array
    {
        return [
            'multi-token password' => ['password=alpha SYNTH_PASSWORD_MARKER omega', 'SYNTH_PASSWORD_MARKER'],
            'private key' => ['private_key=alpha SYNTH_PRIVATE_KEY_MARKER omega', 'SYNTH_PRIVATE_KEY_MARKER'],
            'API key' => ['api_key: alpha SYNTH_API_KEY_MARKER omega', 'SYNTH_API_KEY_MARKER'],
            'token' => ['token=alpha SYNTH_TOKEN_MARKER omega', 'SYNTH_TOKEN_MARKER'],
            'authorization header' => ['Authorization: Digest username="operator", response="SYNTH_AUTHORIZATION_MARKER"', 'SYNTH_AUTHORIZATION_MARKER'],
            'cookie header' => ['Cookie: sid=SYNTH_COOKIE_MARKER; theme=dark', 'SYNTH_COOKIE_MARKER'],
            'customer name' => ['customer_name=Alice SYNTH_CUSTOMER_MARKER', 'SYNTH_CUSTOMER_MARKER'],
            'postal address' => ['address=10 Downing Street SYNTH_ADDRESS_MARKER', 'SYNTH_ADDRESS_MARKER'],
            'social security number' => ['ssn=SYNTH_SSN_MARKER', 'SYNTH_SSN_MARKER'],
            'card number' => ['card_number=SYNTH_CARD_MARKER', 'SYNTH_CARD_MARKER'],
            'structured personal name' => ['{"name":"Alice SYNTH_PERSON_MARKER"}', 'SYNTH_PERSON_MARKER'],
            'encoded API key' => ['api%5Fkey%3A%20alpha%20SYNTH_ENCODED_KEY_MARKER%20omega', 'SYNTH_ENCODED_KEY_MARKER'],
            'email address' => ['Contact person@example.test', 'person@example.test'],
            'phone number' => ['Call +44 7700 900123', '+44 7700 900123'],
            'IP address' => ['Client 192.0.2.10', '192.0.2.10'],
        ];
    }
}
