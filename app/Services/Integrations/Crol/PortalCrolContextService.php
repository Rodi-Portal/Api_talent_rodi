<?php

namespace App\Services\Integrations\Crol;

use Illuminate\Support\Facades\DB;

class PortalCrolContextService
{
    public function obtener(int $portalId): array
    {
        $portal = DB::connection('portal_main')
            ->table('portal as p')
            ->leftJoin(
                'datos_facturacion as df',
                'df.id',
                '=',
                'p.id_datos_facturacion'
            )
            ->leftJoin(
                'domicilios as d',
                'd.id',
                '=',
                'p.id_domicilios'
            )
            ->leftJoin('usuarios_portal as up', function ($join) {
                $join->on('up.id', '=', 'p.id_usuario_portal')
                    ->on('up.id_portal', '=', 'p.id')
                    ->where('up.status', 1)
                    ->where('up.eliminado', 0);
            })
            ->leftJoin(
                'datos_generales as dg',
                'dg.id',
                '=',
                'up.id_datos_generales'
            )
            ->where('p.id', $portalId)
            ->select([
                'p.id as portal_id',
                'p.nombre',
                'p.status',
                'p.bloqueado',
                'p.crol',
                'df.razon_social',
                'df.rfc',
                'df.regimen',
                'df.forma_pago',
                'df.metodo_pago',
                'df.uso_cfdi',
                'd.pais',
                'd.estado',
                'd.ciudad',
                'd.colonia',
                'd.calle',
                'd.exterior',
                'd.interior',
                'd.cp',
                'dg.nombre as contacto_nombre',
                'dg.paterno as contacto_paterno',
                'dg.correo as contacto_email',
                'dg.telefono as contacto_telefono',
            ])
            ->first();

        abort_if($portal === null, 404, 'Portal no encontrado.');

        abort_if(
            (int) $portal->status !== 1 ||
            (int) $portal->bloqueado !== 0,
            403,
            'El portal no está habilitado.'
        );

        abort_if(
            (int) $portal->crol !== 1,
            403,
            'La integración con CROL no está habilitada para este portal.'
        );

        return [
            'portal_id' => (int) $portal->portal_id,
            'nombre' => $portal->nombre,
            'domicilio' => [
                'pais' => $portal->pais,
                'estado' => $portal->estado,
                'ciudad' => $portal->ciudad,
                'colonia' => $portal->colonia,
                'calle' => $portal->calle,
                'exterior' => $portal->exterior,
                'interior' => $portal->interior,
                'cp' => $portal->cp,
            ],
            'contacto' => [
                'nombre' => $portal->contacto_nombre,
                'paterno' => $portal->contacto_paterno,
                'materno' => '',
                'email' => $portal->contacto_email,
                'telefono' => $portal->contacto_telefono,
            ],
            'facturacion' => [
                'razon_social' => $portal->razon_social,
                'rfc' => $portal->rfc,
                'regimen' => $portal->regimen,
                'forma_pago' => $portal->forma_pago,
                'metodo_pago' => $portal->metodo_pago,
                'uso_cfdi' => $portal->uso_cfdi,
            ],
        ];
    }
}