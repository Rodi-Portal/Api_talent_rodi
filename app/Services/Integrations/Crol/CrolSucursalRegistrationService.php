<?php

namespace App\Services\Integrations\Crol;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class CrolSucursalRegistrationService
{
    public function __construct(
        private TalentSafeIntegrationsApiClient $integrationsApi,
    ) {}

    public function registrar(
        int $portalId,
        int $clienteId,
    ): array {
        $cliente = DB::connection('portal_main')
            ->table('cliente as c')
            ->leftJoin(
                'domicilios as d',
                'd.id',
                '=',
                'c.id_domicilios'
            )
            ->leftJoin(
                'datos_generales as dg',
                'dg.id',
                '=',
                'c.id_datos_generales'
            )
            ->where('c.id', $clienteId)
            ->where('c.id_portal', $portalId)
            ->where('c.status', 1)
            ->where('c.eliminado', 0)
            ->select([
                'c.id',
                'c.nombre',
                'c.clave',
                'c.crol_sucursal_id',
                'c.crol_sucursal_nomina_id',

                'd.calle',
                'd.exterior',
                'd.interior',
                'd.colonia',
                'd.cp',

                'dg.telefono',
            ])
            ->first();

        if ($cliente === null) {
            throw new RuntimeException(
                'La sucursal TalentSafe no existe o no está habilitada.'
            );
        }

        if ($cliente->crol_sucursal_id !== null) {
            throw new RuntimeException(
                'La sucursal ya está registrada en CROL.'
            );
        }

        $payload = [
            'nombre' =>
                trim((string) $cliente->nombre),

            'codigo' =>
                trim((string) $cliente->clave),

            /*
             * El registro ya fue filtrado por
             * status = 1 y eliminado = 0.
             */
            'activo' => true,

            'domicilio' => [
                'calle' =>
                    $this->limpiar(
                        $cliente->calle
                    ),

                'exterior' =>
                    $this->limpiar(
                        $cliente->exterior
                    ),

                'interior' =>
                    $this->limpiar(
                        $cliente->interior
                    ),

                'colonia' =>
                    $this->limpiar(
                        $cliente->colonia
                    ),

                'cp' =>
                    $this->limpiar(
                        $cliente->cp
                    ),
            ],

            'telefono' =>
                $this->limpiar(
                    $cliente->telefono
                ),
        ];

        $response =
            $this->integrationsApi
                ->crolCrearSucursal(
                    portalId: $portalId,
                    payload: $payload,
                );

        $json = $response->json();

        if (
            ! $response->successful() ||
            ! is_array($json) ||
            ($json['status'] ?? false) !== true
        ) {
            $message =
                is_array($json) &&
                is_string($json['message'] ?? null)
                    ? trim($json['message'])
                    : '';

            throw new RuntimeException(
                $message !== ''
                    ? $message
                    : 'No fue posible registrar la sucursal en CROL.'
            );
        }

        $data = $json['data'] ?? null;

        if (! is_array($data)) {
            throw new RuntimeException(
                'La API de integraciones no devolvió los datos de la sucursal.'
            );
        }

        $crolSucursalId =
            (int) ($data['sucursal_id'] ?? 0);

        if ($crolSucursalId <= 0) {
            throw new RuntimeException(
                'La API de integraciones no devolvió un ID de sucursal CROL válido.'
            );
        }

        /*
         * Guardamos únicamente el ID administrativo.
         *
         * El ID de Nómina CROL sigue siendo independiente
         * y todavía no conocemos una relación confiable
         * para obtenerlo automáticamente.
         */
        $actualizados = DB::connection('portal_main')
            ->table('cliente')
            ->where('id', $clienteId)
            ->where('id_portal', $portalId)
            ->whereNull('crol_sucursal_id')
            ->update([
                'crol_sucursal_id' =>
                    $crolSucursalId,
            ]);

        if ($actualizados !== 1) {
            throw new RuntimeException(
                'CROL registró la sucursal, pero TalentSafe no pudo guardar su identificador.'
            );
        }

        return [
            'id' =>
                (int) $cliente->id,

            'nombre' =>
                $cliente->nombre,

            'clave' =>
                $cliente->clave,

            'crol_sucursal_id' =>
                $crolSucursalId,

            'crol_sucursal_nomina_id' =>
                $cliente->crol_sucursal_nomina_id !== null
                    ? (int) $cliente->crol_sucursal_nomina_id
                    : null,

            'estado_crol' =>
                $cliente->crol_sucursal_nomina_id !== null
                    ? 'sincronizada'
                    : 'vinculada_parcialmente',
        ];
    }

    private function limpiar(
        mixed $valor,
    ): string {
        if ($valor === null) {
            return '';
        }

        $valor = trim(
            (string) $valor
        );

        if (
            $valor === '' ||
            strtoupper($valor) === 'NULL'
        ) {
            return '';
        }

        return $valor;
    }
}
