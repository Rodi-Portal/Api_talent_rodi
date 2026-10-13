<?php

namespace App\Services\Integrations\Crol;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class CrolSucursalLocalSaveService
{
    public function guardar(
        int $portalId,
        int $clienteId,
        array $datos,
    ): array {
        $db = DB::connection('portal_main');

        return $db->transaction(function () use (
            $db,
            $portalId,
            $clienteId,
            $datos,
        ) {
            $cliente = $db
                ->table('cliente')
                ->where('id', $clienteId)
                ->where('id_portal', $portalId)
                ->where('status', 1)
                ->where('eliminado', 0)
                ->lockForUpdate()
                ->first();

            if ($cliente === null) {
                throw new RuntimeException(
                    'La sucursal TalentSafe no existe o no está habilitada.'
                );
            }

            /*
             * Datos principales del cliente/sucursal.
             */
            $nombre = trim(
                (string) $datos['nombre']
            );

            $clave = trim(
                (string) $datos['clave']
            );

            $db
                ->table('cliente')
                ->where('id', $clienteId)
                ->where('id_portal', $portalId)
                ->update([
                    'nombre' => $nombre,
                    'clave' => $clave,
                ]);

            /*
             * Domicilio.
             *
             * Si ya existe una relación, actualizamos.
             * Si no existe o apunta a un registro inexistente,
             * creamos uno nuevo y lo vinculamos al cliente.
             */
            $domicilio = $datos['domicilio'] ?? [];

            $datosDomicilio = [
                'calle' => $this->nullable(
                    $domicilio['calle'] ?? null
                ),

                'exterior' => $this->nullable(
                    $domicilio['exterior'] ?? null
                ),

                'interior' => $this->nullable(
                    $domicilio['interior'] ?? null
                ),

                'colonia' => $this->nullable(
                    $domicilio['colonia'] ?? null
                ),

                'cp' => $this->nullable(
                    $domicilio['cp'] ?? null
                ),
            ];

            $domicilioId =
                $cliente->id_domicilios !== null
                    ? (int) $cliente->id_domicilios
                    : 0;

            $domicilioExiste =
                $domicilioId > 0 &&
                $db
                    ->table('domicilios')
                    ->where('id', $domicilioId)
                    ->exists();

            if ($domicilioExiste) {
                $db
                    ->table('domicilios')
                    ->where('id', $domicilioId)
                    ->update($datosDomicilio);
            } else {
                $domicilioId = (int) $db
                    ->table('domicilios')
                    ->insertGetId($datosDomicilio);

                if ($domicilioId <= 0) {
                    throw new RuntimeException(
                        'No fue posible guardar el domicilio de la sucursal.'
                    );
                }

                $db
                    ->table('cliente')
                    ->where('id', $clienteId)
                    ->where('id_portal', $portalId)
                    ->update([
                        'id_domicilios' =>
                            $domicilioId,
                    ]);
            }

            /*
             * Datos generales.
             *
             * Actualmente para CROL necesitamos
             * principalmente el teléfono.
             */
            $telefono = $this->nullable(
                $datos['telefono'] ?? null
            );

            $datosGeneralesId =
                $cliente->id_datos_generales !== null
                    ? (int) $cliente->id_datos_generales
                    : 0;

            $datosGeneralesExiste =
                $datosGeneralesId > 0 &&
                $db
                    ->table('datos_generales')
                    ->where('id', $datosGeneralesId)
                    ->exists();

            if ($datosGeneralesExiste) {
                $db
                    ->table('datos_generales')
                    ->where('id', $datosGeneralesId)
                    ->update([
                        'telefono' => $telefono,
                    ]);
            } else {
                $datosGeneralesId = (int) $db
                    ->table('datos_generales')
                    ->insertGetId([
                        'telefono' => $telefono,
                    ]);

                if ($datosGeneralesId <= 0) {
                    throw new RuntimeException(
                        'No fue posible guardar los datos generales de la sucursal.'
                    );
                }

                $db
                    ->table('cliente')
                    ->where('id', $clienteId)
                    ->where('id_portal', $portalId)
                    ->update([
                        'id_datos_generales' =>
                            $datosGeneralesId,
                    ]);
            }

            return [
                'id' =>
                    (int) $cliente->id,

                'nombre' =>
                    $nombre,

                'clave' =>
                    $clave,

                'domicilio' => [
                    'id' =>
                        $domicilioId,

                    'calle' =>
                        $datosDomicilio['calle'],

                    'exterior' =>
                        $datosDomicilio['exterior'],

                    'interior' =>
                        $datosDomicilio['interior'],

                    'colonia' =>
                        $datosDomicilio['colonia'],

                    'cp' =>
                        $datosDomicilio['cp'],
                ],

                'telefono' =>
                    $telefono,

                'id_datos_generales' =>
                    $datosGeneralesId,

                'crol_sucursal_id' =>
                    $cliente->crol_sucursal_id !== null
                        ? (int) $cliente->crol_sucursal_id
                        : null,

                'crol_sucursal_nomina_id' =>
                    $cliente->crol_sucursal_nomina_id !== null
                        ? (int) $cliente->crol_sucursal_nomina_id
                        : null,
            ];
        });
    }

    private function nullable(
        mixed $valor,
    ): ?string {
        if ($valor === null) {
            return null;
        }

        $valor = trim(
            (string) $valor
        );

        if (
            $valor === '' ||
            strtoupper($valor) === 'NULL'
        ) {
            return null;
        }

        return $valor;
    }
}
