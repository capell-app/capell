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
            'comma-bearing password' => ['password=alpha,SYNTH_COMMA_PASSWORD_MARKER omega', 'SYNTH_COMMA_PASSWORD_MARKER'],
            'semicolon-bearing private key' => ['private_key=alpha;SYNTH_SEMICOLON_KEY_MARKER omega', 'SYNTH_SEMICOLON_KEY_MARKER'],
            'quoted delimiters' => ['password="alpha,SYNTH_QUOTED_DELIMITER_MARKER;omega" retryable=true', 'SYNTH_QUOTED_DELIMITER_MARKER'],
            'private key' => ['private_key=alpha SYNTH_PRIVATE_KEY_MARKER omega', 'SYNTH_PRIVATE_KEY_MARKER'],
            'PEM private key' => ['private_key=-----BEGIN PRIVATE ' . "KEY-----\nSYNTH_PEM_MARKER\n-----END PRIVATE KEY-----", 'SYNTH_PEM_MARKER'],
            'API key' => ['api_key: alpha SYNTH_API_KEY_MARKER omega', 'SYNTH_API_KEY_MARKER'],
            'token' => ['token=alpha SYNTH_TOKEN_MARKER omega', 'SYNTH_TOKEN_MARKER'],
            'authorization header' => ['Authorization: Digest username="operator", response="SYNTH_AUTHORIZATION_MARKER"', 'SYNTH_AUTHORIZATION_MARKER'],
            'cookie header' => ['Cookie: sid=SYNTH_COOKIE_MARKER; theme=dark', 'SYNTH_COOKIE_MARKER'],
            'customer name' => ['customer_name=Alice SYNTH_CUSTOMER_MARKER', 'SYNTH_CUSTOMER_MARKER'],
            'postal address' => ['address=10 Downing Street SYNTH_ADDRESS_MARKER', 'SYNTH_ADDRESS_MARKER'],
            'multiline postal address' => ["address=10 Downing Street\nLondon SYNTH_MULTILINE_ADDRESS_MARKER", 'SYNTH_MULTILINE_ADDRESS_MARKER'],
            'social security number' => ['ssn=SYNTH_SSN_MARKER', 'SYNTH_SSN_MARKER'],
            'card number' => ['card_number=SYNTH_CARD_MARKER', 'SYNTH_CARD_MARKER'],
            'structured personal name' => ['{"name":"Alice SYNTH_PERSON_MARKER"}', 'SYNTH_PERSON_MARKER'],
            'encoded API key' => ['api%5Fkey%3A%20alpha%20SYNTH_ENCODED_KEY_MARKER%20omega', 'SYNTH_ENCODED_KEY_MARKER'],
            'URL encoded delimiters' => [rawurlencode('password=alpha,SYNTH_URL_DELIMITER_MARKER;omega'), 'SYNTH_URL_DELIMITER_MARKER'],
            'JSON encoded assignment' => [json_encode(['detail' => 'api_key: alpha,SYNTH_JSON_MARKER;omega'], JSON_THROW_ON_ERROR), 'SYNTH_JSON_MARKER'],
            'HTML decimal delimiter' => ['password&#61;SYNTH_HTML_DECIMAL_MARKER', 'SYNTH_HTML_DECIMAL_MARKER'],
            'HTML hexadecimal delimiter' => ['private_key&#x3D;SYNTH_HTML_HEXADECIMAL_MARKER', 'SYNTH_HTML_HEXADECIMAL_MARKER'],
            'HTML named delimiter' => ['client_secret&equals;SYNTH_HTML_NAMED_MARKER', 'SYNTH_HTML_NAMED_MARKER'],
            'email address' => ['Contact person@example.test', 'person@example.test'],
            'phone number' => ['Call +44 7700 900123', '+44 7700 900123'],
            'IP address' => ['Client 192.0.2.10', '192.0.2.10'],
        ];
    }
}
