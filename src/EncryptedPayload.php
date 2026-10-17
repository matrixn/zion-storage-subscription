<?php
declare(strict_types=1);
namespace Zion;

final class EncryptedPayload
{
    public static function encode(array $data): string
    {
        if (strlen(env('APP_KEY')) < 32) throw new \RuntimeException('APP_KEY must contain at least 32 characters');
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce.sodium_crypto_secretbox(json_encode($data, JSON_THROW_ON_ERROR), $nonce, hash('sha256', env('APP_KEY'), true)));
    }

    public static function decode(string $encoded): array
    {
        $bytes = base64_decode($encoded, true);
        if ($bytes === false || strlen($bytes) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) throw new \RuntimeException('Invalid encrypted payload');
        $raw = sodium_crypto_secretbox_open(substr($bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), hash('sha256', env('APP_KEY'), true));
        if ($raw === false) throw new \RuntimeException('Cannot decrypt payload');
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }
}
