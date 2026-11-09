<?php

namespace App\Services\Incidencias;

use App\Models\Auth\AdministradorAuth;
use App\Services\Auth\AdminClientScopeService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Datos actuales para generar la constancia PDF en Vue.
 * Solo lectura: no genera archivos ni realiza escrituras en BD.
 */
final class IncidenciaConstanciaPreviewService
{
    public function __construct(
        private readonly AdminClientScopeService $clientScope
    ) {
    }

    public function obtener(int $eventoId, AdministradorAuth $admin): array
    {
        $portalId = (int) $admin->id_portal;
        $db = DB::connection('portal_main');

        // La sucursal histórica pertenece al EVENTO, no a la sucursal
        // actual del empleado (pudo haber cambiado de sucursal).
        $evento = $db->table('calendario_eventos as ce')
            ->join('empleados as e', function ($join) {
                $join->on('e.id', '=', 'ce.id_empleado')
                    ->on('e.id_portal', '=', 'ce.id_portal');
            })
            ->join('portal as p', 'p.id', '=', 'ce.id_portal')
            ->join('cliente as c', function ($join) {
                $join->on('c.id', '=', 'ce.id_cliente')
                    ->on('c.id_portal', '=', 'ce.id_portal');
            })
            ->leftJoin('eventos_option as eo', 'eo.id', '=', 'ce.id_tipo')
            ->where('ce.id', $eventoId)
            ->where('ce.id_portal', $portalId)
            ->where('ce.eliminado', 0)
            ->where('e.eliminado', 0)
            ->where(function ($query) use ($portalId) {
                $query->whereNull('eo.id_portal')
                    ->orWhere('eo.id_portal', $portalId);
            })
            ->first([
                'ce.id', 'ce.id_portal', 'ce.id_cliente', 'ce.id_empleado',
                'ce.id_usuario', 'ce.id_tipo', 'ce.inicio', 'ce.fin',
                'ce.dias_evento', 'ce.descripcion', 'ce.estado',
                'ce.estado_aprobacion', 'ce.origen_evento',
                'ce.requiere_aprobacion', 'ce.created_at', 'ce.updated_at',
                'ce.tipo_incapacidad_sat',
                'eo.name as tipo_nombre',
                'p.nombre as portal_nombre', 'p.logo as portal_logo',
                'c.nombre as sucursal_nombre',
                'e.id_empleado as numero_empleado',
                'e.nombre as empleado_nombre',
                'e.paterno as empleado_paterno',
                'e.materno as empleado_materno',
                'e.departamento as empleado_departamento',
                'e.puesto as empleado_puesto',
            ]);

        if (! $evento || (int) $evento->id_cliente <= 0) {
            throw new NotFoundHttpException('Incidencia no encontrada.');
        }

        // No basta conocer el ID: la sucursal debe estar autorizada
        // para el administrador autenticado en el portal correcto.
        $this->clientScope->authorizeRequestedClients(
            $admin,
            [(int) $evento->id_cliente]
        );

        $aprobaciones = $db->table('checador_evento_aprobaciones as a')
            ->leftJoin('empleados as ap', 'ap.id', '=', 'a.id_empleado_aprobador')
            ->leftJoin('empleados as so', 'so.id', '=', 'a.id_empleado_solicitante')
            ->where('a.id_evento', $eventoId)
            ->where('a.id_portal', $portalId)
            ->where('a.id_cliente', (int) $evento->id_cliente)
            ->orderBy('a.nivel')
            ->orderBy('a.id')
            ->get([
                'a.id', 'a.nivel', 'a.estatus', 'a.comentario',
                'a.fecha_respuesta', 'a.created_at',
                'a.id_empleado_aprobador', 'a.id_empleado_solicitante',
                'ap.nombre as aprobador_nombre',
                'ap.paterno as aprobador_paterno',
                'ap.materno as aprobador_materno',
                'so.nombre as solicitante_nombre',
                'so.paterno as solicitante_paterno',
                'so.materno as solicitante_materno',
            ]);

        $solicitante = $aprobaciones->first(
            fn ($a) => (int) $a->id_empleado_solicitante > 0
        );

        $detalles = $db->table('checador_evento_detalles')
            ->where('id_evento', $eventoId)
            ->orderBy('id')
            ->get([
                'id', 'tipo_operativo', 'fecha', 'hora_inicio',
                'hora_fin', 'minutos_detectados', 'minutos_aprobados',
                'minutos_pagables', 'modo_aprobacion',
                'confirmacion_empleado_estado', 'confirmado_at',
                'confirmacion_comentario', 'aprobado_por_tipo',
                'aprobado_por_id', 'aprobado_at', 'observaciones',
            ]);

        // Saldo vigente de vacaciones; nunca se reconstruye un saldo histórico.
        // Si hay cero o varios registros laborales, no elegimos uno arbitrariamente.
        $vacaciones = null;
        if ((int) $evento->id_tipo === 1) {
            $saldos = $db->table('laborales_empleado')
                ->where('id_empleado', (int) $evento->id_empleado)
                ->limit(2)
                ->pluck('vacaciones_disponibles');

            $vacaciones = [
                'dias_solicitados' => $evento->dias_evento !== null
                    ? (int) $evento->dias_evento : null,
                'saldo_actual_disponible' => $saldos->count() === 1
                    && is_numeric($saldos->first())
                        ? (float) $saldos->first() : null,
                'saldo_historico_disponible' => false,
            ];
        }

        return [
            'version_esquema' => 1,
            'identidad' => [
                'portal' => [
                    'id' => $portalId,
                    'nombre' => $evento->portal_nombre,
                    // El logo se resolverá como archivo local y se
                    // validará antes de incluirlo en el PDF final.
                    'tiene_logo' => trim((string) $evento->portal_logo) !== '',
                    'logo_data_uri' => $this->logoPortalDataUri($portalId, $evento->portal_logo),
                ],
                'sucursal' => [
                    'id' => (int) $evento->id_cliente,
                    'nombre' => $evento->sucursal_nombre,
                    // La tabla cliente no tiene columna logo en el
                    // esquema diagnosticado. No inventar su origen.
                    'tiene_logo_propio' => null,
                ],
            ],
            'evento' => [
                'id' => (int) $evento->id,
                'tipo_id' => (int) $evento->id_tipo,
                'tipo' => $evento->tipo_nombre,
                'inicio' => $evento->inicio,
                'fin' => $evento->fin,
                'dias' => $evento->dias_evento !== null
                    ? (int) $evento->dias_evento : null,
                'descripcion' => $evento->descripcion,
                'estado' => $evento->estado,
                'estado_aprobacion' => $evento->estado_aprobacion,
                'requiere_aprobacion' => (bool) $evento->requiere_aprobacion,
                'origen' => $evento->origen_evento,
                'canal_solicitud' => (
                    $evento->origen_evento === 'checador'
                    && (bool) $evento->requiere_aprobacion
                    && (int) $evento->id_usuario > 0
                    && (int) $evento->id_usuario === (int) $evento->id_empleado
                ) ? 'miportal' : null,
                'tipo_incapacidad_sat' => $evento->tipo_incapacidad_sat,
                'registrado_el' => $evento->created_at,
                'actualizado_el' => $evento->updated_at,
            ],
            'colaborador' => [
                'id' => (int) $evento->id_empleado,
                'numero' => $evento->numero_empleado,
                'nombre' => $this->nombreCompleto(
                    $evento->empleado_nombre,
                    $evento->empleado_paterno,
                    $evento->empleado_materno
                ),
                'departamento' => $evento->empleado_departamento,
                'puesto' => $evento->empleado_puesto,
            ],
            'vacaciones' => $vacaciones,
            'solicitud' => [
                'empleado_solicitante_id' => $solicitante?->id_empleado_solicitante,
                'empleado_solicitante' => $solicitante
                    ? $this->nombreCompleto(
                        $solicitante->solicitante_nombre,
                        $solicitante->solicitante_paterno,
                        $solicitante->solicitante_materno
                    ) : null,
                // No confundir ce.id_usuario con un aprobador o
                // con una firma: varía según el origen del evento.
                'creado_el' => $evento->created_at,
            ],
            'aprobaciones' => $aprobaciones->map(fn ($a) => [
                'id' => (int) $a->id,
                'nivel' => (int) $a->nivel,
                'aprobador_id' => $a->id_empleado_aprobador,
                'aprobador' => $this->nombreCompleto(
                    $a->aprobador_nombre,
                    $a->aprobador_paterno,
                    $a->aprobador_materno
                ),
                'estado' => $a->estatus,
                'comentario' => $a->comentario,
                'asignado_el' => $a->created_at,
                'respondido_el' => $a->fecha_respuesta,
            ])->values()->all(),
            'detalles_operativos' => $detalles->map(fn ($d) => (array) $d)
                ->values()->all(),
        ];
    }

    private function logoPortalDataUri(int $portalId, ?string $nombreArchivo): ?string
    {
        $padre = dirname(base_path());

        $raices = array_unique(array_filter([
            config('constancia_incidente.portal_root'),
            $padre . DIRECTORY_SEPARATOR . 'portal',
            $padre . DIRECTORY_SEPARATOR . 'rodi_portal_aws',
        ]));

        $nombreArchivo = trim((string) $nombreArchivo);

        $nombreSeguro = $nombreArchivo !== ''
            && strlen($nombreArchivo) <= 180
            && preg_match('/\A[\pL\pN_. -]+\z/u', $nombreArchivo)
            && ! str_contains($nombreArchivo, '..');

        foreach ($raices as $raiz) {
            $almacen = rtrim((string) $raiz, '/\\')
                . DIRECTORY_SEPARATOR . 'storagetalentsafe';

            $raizReal = realpath($almacen);

            if ($raizReal === false) {
                continue;
            }

            $candidatos = [];

            if ($nombreSeguro && $portalId > 0) {
                $candidatos[] = $almacen . '/portales/'
                    . $portalId . '/configuracion/logo/'
                    . $nombreArchivo;
            }

            $candidatos[] = $almacen
                . '/default/logo/logo_nuevo.png';

            $prefijo = rtrim(
                str_replace('\\', '/', $raizReal),
                '/'
            ) . '/';

            foreach ($candidatos as $archivo) {
                $real = realpath($archivo);

                if (
                    $real === false ||
                    ! str_starts_with(
                        str_replace('\\', '/', $real),
                        $prefijo
                    ) ||
                    ! is_file($real) ||
                    ! is_readable($real)
                ) {
                    continue;
                }

                $peso = filesize($real);

                if (
                    $peso === false ||
                    $peso < 1 ||
                    $peso > 2097152
                ) {
                    continue;
                }

                $info = @getimagesize($real);
                $mime = $info['mime'] ?? null;

                if (
                    ! in_array(
                        $mime,
                        ['image/png', 'image/jpeg', 'image/webp'],
                        true
                    ) ||
                    ($info[0] ?? 0) > 4096 ||
                    ($info[1] ?? 0) > 4096
                ) {
                    continue;
                }

                $contenido = @file_get_contents($real);

                if ($contenido !== false) {
                    return 'data:' . $mime . ';base64,'
                        . base64_encode($contenido);
                }
            }
        }

        return null;
    }

    private function nombreCompleto(...$partes): string
    {
        return trim(implode(' ', array_filter(array_map(
            fn ($parte) => trim((string) $parte),
            $partes
        ))));
    }
}
