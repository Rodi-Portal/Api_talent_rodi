<?php
namespace App\Services\Integrations\Crol;

use App\Models\ClienteTalent;
use RuntimeException;

class CrolSucursalesContextService
{
    public function __construct(
        private TalentSafeIntegrationsApiClient $integrationsApi,
    ) {}

    public function obtener(
        int $portalId,
    ): array {
        $clientes = ClienteTalent::query()
            ->from('cliente as c')
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
            ->where('c.id_portal', $portalId)
            ->where('c.status', 1)
            ->where('c.eliminado', 0)
            ->orderBy('c.id')
            ->get([
                'c.id',
                'c.nombre',
                'c.clave',
                'c.crol_sucursal_id',
                'c.crol_sucursal_nomina_id',

                'd.pais',
                'd.estado',
                'd.ciudad',
                'd.colonia',
                'd.calle',
                'd.exterior',
                'd.interior',
                'd.cp',

                'dg.telefono',
            ])
            ->map(function ($cliente) {
                $adminId = $cliente->crol_sucursal_id !== null
                    ? (int) $cliente->crol_sucursal_id
                    : null;

                $nominaId = $cliente->crol_sucursal_nomina_id !== null
                    ? (int) $cliente->crol_sucursal_nomina_id
                    : null;

                $estado = match (true) {
                    $adminId !== null && $nominaId !== null => 'sincronizada',

                    $adminId !== null || $nominaId !== null => 'vinculada_parcialmente',

                    default
                                                            => 'pendiente',
                };
                return [
                    'id'                      => (int) $cliente->id,
                    'nombre'                  => $cliente->nombre,
                    'clave'                   => $cliente->clave,

                    'crol_sucursal_id'        => $adminId,
                    'crol_sucursal_nomina_id' => $nominaId,

                    'estado_crol'             => $estado,

                    'domicilio'               => [
                        'pais'     => $cliente->pais,
                        'estado'   => $cliente->estado,
                        'ciudad'   => $cliente->ciudad,
                        'colonia'  => $cliente->colonia,
                        'calle'    => $cliente->calle,
                        'exterior' => $cliente->exterior,
                        'interior' => $cliente->interior,
                        'cp'       => $cliente->cp,
                    ],

                    'telefono'                => $cliente->telefono,
                ];
            })
            ->values()
            ->all();

        $response =
        $this->integrationsApi->crolSucursales(
            $portalId
        );

        $json = $response->json();

        if (
            ! $response->successful() ||
            ! is_array($json) ||
            ($json['status'] ?? false) !== true
        ) {
            throw new RuntimeException(
                is_array($json) &&
                is_string($json['message'] ?? null)
                    ? $json['message']
                    : 'No fue posible consultar las sucursales de CROL.'
            );
        }

        $remote = is_array($json['data'] ?? null)
            ? $json['data']
            : [];

        return [
            'clientes' => $clientes,

            'crol'     => [
                'connection_id'     =>
                $remote['connection_id'] ?? null,

                'organization_id'   =>
                $remote['organization_id'] ?? null,

                'sucursales_nomina' =>
                is_array($remote['sucursales'] ?? null)
                    ? $remote['sucursales']
                    : [],
            ],
        ];
    }
}
