<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

final class BackupSigningTestKeys
{
    public static function configure(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        config([
            'backup.signing.key_id' => 'test-key',
            'backup.signing.secret_key' => base64_encode(sodium_crypto_sign_secretkey($keypair)),
            'backup.signing.public_keys' => [
                'test-key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            ],
        ]);
    }
}
