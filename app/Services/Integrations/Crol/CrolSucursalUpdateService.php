<?php

namespace App\Services\Integrations\Crol;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class CrolSucursalUpdateService
{
    public function __construct(
        private TalentSafeIntegrationsApiClient $integrationsApi,
    ) {}

    public function actualizar(
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

        if ($cliente->crol_sucursal_id === null) {
            throw new RuntimeException(
                'La sucursal todavía no está vinculada con CROL.'
            );
        }

        $crolSucursalId =
            (int) $cliente->crol_sucursal_id;

        if ($crolSucursalId <= 0) {
            throw new RuntimeException(
                'La sucursal tiene un ID CROL inválido.'
            );
        }

        $payload = [
            'nombre' =>
                trim((string) $cliente->nombre),

            'codigo' =>
                trim((string) $cliente->clave),

            /*
             * La sucursal ya fue filtrada por
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
                ->crolActualizarSucursal(
                    portalId: $portalId,
                    sucursalId: $crolSucursalId,
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
                    : 'No fue posible actualizar la sucursal en CROL.'
            );
        }

        $data = $json['data'] ?? null;

        if (! is_array($data)) {
            throw new RuntimeException(
                'La API de integraciones no devolvió los datos de la sucursal actualizada.'
            );
        }

        $returnedSucursalId =
            (int) ($data['sucursal_id'] ?? 0);

        if ($returnedSucursalId <= 0) {
            throw new RuntimeException(
                'La API de integraciones no devolvió un ID de sucursal CROL válido.'
            );
        }

        /*
         * Protección adicional:
         * una actualización nunca debe cambiar
         * la vinculación administrativa existente.
         */
        if ($returnedSucursalId !== $crolSucursalId) {
            throw new RuntimeException(
                'CROL devolvió un ID de sucursal diferente al vinculado en TalentSafe.'
            );
        }

        /*
         * NO actualizamos crol_sucursal_id.
         *
         * El objetivo de este flujo es actualizar
         * la sucursal ya vinculada, no crear ni
         * modificar su relación.
         */
        return [
            'id' =>
                (int) $cliente->id,

            'nombre' =>
                $data['nombre']
                    ?? $cliente->nombre,

            'clave' =>
                $data['codigo']
                    ?? $cliente->clave,

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
