<?php

declare(strict_types=1);

namespace App\Casts;

use App\Casts\EmpleadoFieldEncryption;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

final class EmpleadoEncryptedString implements CastsAttributes
{
    public function get(
        Model $model,
        string $key,
        mixed $value,
        array $attributes
    ): ?string {
        return EmpleadoFieldEncryption::decrypt($value);
    }

    public function set(
        Model $model,
        string $key,
        mixed $value,
        array $attributes
    ): ?string {
        return EmpleadoFieldEncryption::prepareForStorage(
            $value === null ? null : (string) $value,
            $attributes[$key] ?? null
        );
    }
}