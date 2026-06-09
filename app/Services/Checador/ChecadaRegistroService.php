<?php

namespace App\Services\Checador;

use Illuminate\Support\Facades\DB;

class ChecadaRegistroService
{
    private string $connection = 'portal_main';

    public function insertar(array $data, array $resultadoValidacion, array $metadata = []): int
    {
        return DB::connection($this->connection)
            ->table('checadas')
            ->insertGetId([
                'id_portal'          => $data['id_portal'],
                'id_cliente'         => $data['id_cliente'],
                'id_empleado'        => $data['id_empleado'],
                'id_asignacion'      => $resultadoValidacion['id_asignacion'],

                'fecha'              => date('Y-m-d', strtotime($data['check_time'])),
                'check_time'         => $data['check_time'],

                'tipo'               => $data['tipo'] ?? 'in',
                'clase'              => $data['clase'] ?? 'work',

                'dispositivo'        => $data['dispositivo'] ?? null,
                'origen'             => $data['origen'] ?? 'geoloc',
                'metodo_validacion'  => $data['metodo_validacion'] ?? null,

                'estatus_validacion' => $resultadoValidacion['estatus_validacion'],
                'observacion'        => $resultadoValidacion['motivo'] ?? null,

                'id_ubicacion'       => $resultadoValidacion['id_ubicacion'] ?? null,
                'distancia_metros'   => $resultadoValidacion['distancia_metros'] ?? null,
                'precision_metros'   => $resultadoValidacion['precision_metros'] ?? null,
                'latitud'            => $resultadoValidacion['latitud'] ?? null,
                'longitud'           => $resultadoValidacion['longitud'] ?? null,

                'qr_token'           => $data['qr_token'] ?? null,
                'evidencia_foto'     => $data['foto_path'] ?? null,
                'ip_address'         => request()->ip(),
                'timezone'           => $data['timezone'] ?? null,
                'device_info'        => $data['device_info'] ?? null,

                'metadata'           => ! empty($metadata)
                    ? json_encode($metadata, JSON_UNESCAPED_UNICODE)
                    : null,

                'hash'               => sha1(
                    $data['id_empleado'] .
                    $data['check_time'] .
                    microtime(true)
                ),
            ]);
    }
}