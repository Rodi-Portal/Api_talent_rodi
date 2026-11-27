<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Support\Facades\Crypt;
use RuntimeException;

final class EmpleadoFieldEncryption
{
    private const PREFIX = 'enc:v2:';
    private const LEGACY_PREFIX = 'enc:v1:';
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    private static ?string $key = null;

    private static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }

        $path = config('empleados_campos_extra.key_path');

        if (
            ! is_string($path)
            || $path === ''
            || ! is_file($path)
            || ! is_readable($path)
        ) {
            throw new RuntimeException(
                'Clave de empleados no disponible.'
            );
        }

        $key = @file_get_contents($path);

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException(
                'Clave de empleados no valida.'
            );
        }

        return self::$key = $key;
    }

    public static function isEncrypted(?string $value): bool
    {
        return $value !== null
            && (
                str_starts_with($value, self::PREFIX)
                || str_starts_with($value, self::LEGACY_PREFIX)
            );
    }

    public static function encrypt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $encrypted = openssl_encrypt(
            $value,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if (
            $encrypted === false
            || strlen($tag) !== self::TAG_LENGTH
        ) {
            throw new RuntimeException(
                'No fue posible cifrar el dato del empleado.'
            );
        }

        return self::PREFIX . base64_encode(
            $iv . $tag . $encrypted
        );
    }

    public static function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (str_starts_with($value, self::LEGACY_PREFIX)) {
            return Crypt::decryptString(
                substr($value, strlen(self::LEGACY_PREFIX))
            );
        }

        if (! str_starts_with($value, self::PREFIX)) {
            return $value;
        }

        $raw = base64_decode(
            substr($value, strlen(self::PREFIX)),
            true
        );

        $offset = self::IV_LENGTH + self::TAG_LENGTH;

        if ($raw === false || strlen($raw) <= $offset) {
            throw new RuntimeException(
                'Formato de cifrado de empleado no valido.'
            );
        }

        $iv = substr($raw, 0, self::IV_LENGTH);

        $tag = substr(
            $raw,
            self::IV_LENGTH,
            self::TAG_LENGTH
        );

        $encrypted = substr($raw, $offset);

        $decrypted = openssl_decrypt(
            $encrypted,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            ''
        );

        if ($decrypted === false) {
            throw new RuntimeException(
                'No fue posible autenticar el dato cifrado.'
            );
        }

        return $decrypted;
    }

    public static function prepareForStorage(
        ?string $value,
        ?string $original = null
    ): ?string {
        if ($value === null || $value === '') {
            return $value;
        }

        if (
            $original !== null
            && str_starts_with($original, self::PREFIX)
            && self::decrypt($original) === $value
        ) {
            return $original;
        }

        return self::encrypt($value);
    }
}