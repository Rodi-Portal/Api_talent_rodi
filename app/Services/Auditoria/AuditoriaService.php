<?php

namespace App\Services\Auditoria;

use App\Models\AuditoriaEvento;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonSerializable;
use Throwable;

class AuditoriaService
{
    /**
     * Registra un evento funcional sin interrumpir la acción principal
     * si la escritura de auditoría falla.
     */
    public function registrar(
        array $evento,
        ?Request $request = null
    ): ?AuditoriaEvento {
        try {
            $this->validarEvento($evento);

            $request = $request ?: $this->requestActual();

            return AuditoriaEvento::query()->create([
                'id_portal' => $this->integerOrNull(
                    $evento['id_portal'] ?? null
                ),

                'id_cliente' => $this->integerOrNull(
                    $evento['id_cliente'] ?? null
                ),

                'actor_tipo' => $this->texto(
                    $evento['actor_tipo'] ?? 'sistema',
                    40
                ),

                'actor_id' => $this->integerOrNull(
                    $evento['actor_id'] ?? null
                ),

                'actor_nombre' => $this->nullableText(
                    $evento['actor_nombre'] ?? null,
                    200
                ),

                'modulo' => $this->texto(
                    $evento['modulo'],
                    80
                ),

                'entidad_tipo' => $this->texto(
                    $evento['entidad_tipo'],
                    80
                ),

                'entidad_id' => $this->integerOrNull(
                    $evento['entidad_id'] ?? null
                ),

                'accion' => $this->texto(
                    $evento['accion'],
                    80
                ),

                'resultado' => $this->texto(
                    $evento['resultado'] ?? 'exitoso',
                    30
                ),

                'descripcion' => $this->nullableText(
                    $evento['descripcion'] ?? null,
                    5000
                ),

                'datos_anteriores' => $this->sanitize(
                    $evento['datos_anteriores'] ?? null
                ),

                'datos_nuevos' => $this->sanitize(
                    $evento['datos_nuevos'] ?? null
                ),

                'metadatos' => $this->sanitize(
                    $evento['metadatos'] ?? null
                ),

                'request_id' => $this->requestId(
                    $request,
                    $evento['request_id'] ?? null
                ),

                'ip' => $request
                    ? $this->nullableText(
                        $request->ip(),
                        45
                    )
                    : null,

                'user_agent' => $request
                    ? $this->nullableText(
                        $request->userAgent(),
                        2000
                    )
                    : null,

                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::error(
                'No se pudo registrar el evento de auditoría.',
                [
                    'modulo' => $evento['modulo'] ?? null,
                    'entidad_tipo' =>
                        $evento['entidad_tipo'] ?? null,
                    'entidad_id' =>
                        $evento['entidad_id'] ?? null,
                    'accion' => $evento['accion'] ?? null,
                    'message' => $exception->getMessage(),
                ]
            );

            return null;
        }
    }

    private function validarEvento(array $evento): void
    {
        foreach (
            ['modulo', 'entidad_tipo', 'accion']
            as $campo
        ) {
            if (
                ! isset($evento[$campo])
                || trim((string) $evento[$campo]) === ''
            ) {
                throw new \InvalidArgumentException(
                    "El campo de auditoría {$campo} es obligatorio."
                );
            }
        }
    }

    private function requestActual(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        return $request instanceof Request
            ? $request
            : null;
    }

    private function requestId(
        ?Request $request,
        mixed $provided
    ): string {
        $provided = trim((string) $provided);

        if ($provided !== '') {
            return $this->texto($provided, 64);
        }

        if ($request) {
            $attribute = trim(
                (string) $request->attributes->get(
                    'auditoria_request_id',
                    ''
                )
            );

            if ($attribute !== '') {
                return $this->texto($attribute, 64);
            }

            $header = trim(
                (string) $request->header(
                    'X-Request-ID',
                    ''
                )
            );

            $requestId = $header !== ''
                ? $this->texto($header, 64)
                : (string) Str::uuid();

            $request->attributes->set(
                'auditoria_request_id',
                $requestId
            );

            return $requestId;
        }

        return (string) Str::uuid();
    }

    private function sanitize(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if ($value instanceof JsonSerializable) {
            return $this->sanitize(
                $value->jsonSerialize()
            );
        }

        if (is_object($value)) {
            if (method_exists($value, 'toArray')) {
                return $this->sanitize(
                    $value->toArray()
                );
            }

            return '[objeto no serializable]';
        }

        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $key => $item) {
                if ($this->isSensitiveKey((string) $key)) {
                    $sanitized[$key] = '[REDACTADO]';
                    continue;
                }

                $sanitized[$key] = $this->sanitize($item);
            }

            return $sanitized;
        }

        if (is_resource($value)) {
            return '[recurso no serializable]';
        }

        if (is_string($value)) {
            return Str::limit($value, 10000, '...');
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = Str::lower($key);

        return in_array($key, [
            'password',
            'password_confirmation',
            'contrasena',
            'contraseña',
            'token',
            'access_token',
            'refresh_token',
            'authorization',
            'cookie',
            'secret',
            'client_secret',
            'api_key',
            'contenido_archivo',
            'file_content',
        ], true);
    }

    private function integerOrNull(mixed $value): ?int
    {
        if (
            $value === null
            || $value === ''
            || ! is_numeric($value)
        ) {
            return null;
        }

        return (int) $value;
    }

    private function texto(
        mixed $value,
        int $length
    ): string {
        return Str::limit(
            trim((string) $value),
            $length,
            ''
        );
    }

    private function nullableText(
        mixed $value,
        int $length
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === ''
            ? null
            : Str::limit($value, $length, '');
    }
}