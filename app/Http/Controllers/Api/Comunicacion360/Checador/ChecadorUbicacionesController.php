<?php
namespace App\Http\Controllers\Api\Comunicacion360\Checador;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use App\Models\Comunicacion360\Checador\ChecadorUbicacion;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Auth\AdminClientScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ChecadorUbicacionesController extends Controller
{
    public function __construct(
        private AdminClientScopeService $clientScope,
        private AuditoriaService $auditoria
    ) {}
    public function index(Request $request)
    {
        $validated = $request->validate([
            'id_cliente' => [
                'required',
                'integer',
                'min:1',
            ],
        ]);

        $administrator = $this->administrator($request);

        $clientIds = $this->clientScope
            ->authorizeRequestedClients(
                $administrator,
                [(int) $validated['id_cliente']]
            );

        $ubicaciones = ChecadorUbicacion::query()
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->whereIn('id_cliente', $clientIds)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'ok'   => true,
            'data' => $ubicaciones,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_cliente'         => 'required|integer|min:1',
            'nombre'             => 'required|string|max:150',
            'descripcion'        => 'nullable|string',
            'tipo_zona'          => 'required|in:circle,polygon',
            'latitud'            =>
            'required_if:tipo_zona,circle|nullable|numeric',
            'longitud'           =>
            'required_if:tipo_zona,circle|nullable|numeric',
            'radio_metros'       =>
            'required_if:tipo_zona,circle|nullable|integer|min:10',
            'polygon_json'       => 'nullable',
            'direccion'          => 'nullable|string|max:255',
            'referencia'         => 'nullable|string|max:255',
            'activa'             => 'nullable|integer|in:0,1',
            'qr_modo'            =>
            'nullable|in:ninguno,fijo,dinamico,ambos',
            'qr_expira_segundos' =>
            'nullable|integer|min:10|max:3600',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok'     => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $administrator = $this->administrator($request);

        $clientIds = $this->clientScope
            ->authorizeRequestedClients(
                $administrator,
                [(int) $data['id_cliente']]
            );

        $idCliente = (int) $clientIds[0];

        $ubicacion = ChecadorUbicacion::create([
            'id_portal'          =>
            (int) $administrator->id_portal,
            'id_cliente'         =>
            $idCliente,
            'nombre'             =>
            $data['nombre'],
            'descripcion'        =>
            $data['descripcion'] ?? null,
            'tipo_zona'          =>
            $data['tipo_zona'],
            'latitud'            =>
            $data['latitud'] ?? null,
            'longitud'           =>
            $data['longitud'] ?? null,
            'radio_metros'       =>
            $data['radio_metros'] ?? 100,
            'polygon_json'       =>
            $data['polygon_json'] ?? null,
            'direccion'          =>
            $data['direccion'] ?? null,
            'referencia'         =>
            $data['referencia'] ?? null,
            'activa'             =>
            $data['activa'] ?? 1,
            'qr_modo'            =>
            $data['qr_modo'] ?? 'ninguno',
            'qr_expira_segundos' =>
            $data['qr_expira_segundos'] ?? 60,
            'qr_token_fijo_hash' => null,
            'qr_actualizado_en'  => null,
        ]);

        $this->auditoria->registrar([
            'id_portal'    =>
            (int) $administrator->id_portal,
            'id_cliente'   =>
            (int) $ubicacion->id_cliente,
            'actor_tipo'   => 'administrador',
            'actor_id'     => (int) $administrator->id,
            'actor_nombre' =>
            $this->administratorName($administrator),
            'modulo'       => 'comunicacion360',
            'entidad_tipo' => 'ubicacion_checada',
            'entidad_id'   => (int) $ubicacion->id,
            'accion'       => 'crear',
            'resultado'    => 'exitoso',
            'descripcion'  =>
            'Ubicación del checador creada.',
            'datos_nuevos' => [
                'nombre'             =>
                $ubicacion->nombre,
                'tipo_zona'          =>
                $ubicacion->tipo_zona,
                'latitud'            =>
                $ubicacion->latitud,
                'longitud'           =>
                $ubicacion->longitud,
                'radio_metros'       =>
                $ubicacion->radio_metros,
                'polygon_json'       =>
                $ubicacion->polygon_json,
                'direccion'          =>
                $ubicacion->direccion,
                'referencia'         =>
                $ubicacion->referencia,
                'activa'             =>
                (bool) $ubicacion->activa,
                'qr_modo'            =>
                $ubicacion->qr_modo,
                'qr_expira_segundos' =>
                (int) $ubicacion->qr_expira_segundos,
            ],
        ], $request);

        return response()->json([
            'ok'      => true,
            'message' => 'Ubicación creada correctamente.',
            'id'      => (int) $ubicacion->id,
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'nombre'             => 'required|string|max:150',
            'descripcion'        => 'nullable|string',
            'tipo_zona'          => 'required|in:circle,polygon',
            'latitud'            =>
            'required_if:tipo_zona,circle|nullable|numeric',
            'longitud'           =>
            'required_if:tipo_zona,circle|nullable|numeric',
            'radio_metros'       =>
            'required_if:tipo_zona,circle|nullable|integer|min:10',
            'polygon_json'       => 'nullable',
            'direccion'          => 'nullable|string|max:255',
            'referencia'         => 'nullable|string|max:255',
            'activa'             => 'nullable|integer|in:0,1',
            'qr_modo'            =>
            'nullable|in:ninguno,fijo,dinamico,ambos',
            'qr_expira_segundos' =>
            'nullable|integer|min:10|max:3600',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok'     => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $administrator = $this->administrator($request);

        $ubicacion = ChecadorUbicacion::query()
            ->where('id', $id)
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->first();

        if (! $ubicacion) {
            return response()->json([
                'ok'      => false,
                'message' => 'Ubicación no encontrada.',
            ], 404);
        }

        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $ubicacion->id_cliente]
        );

        $datosAnteriores = [
            'nombre'             =>
            $ubicacion->nombre,
            'descripcion'        =>
            $ubicacion->descripcion,
            'tipo_zona'          =>
            $ubicacion->tipo_zona,
            'latitud'            =>
            $ubicacion->latitud,
            'longitud'           =>
            $ubicacion->longitud,
            'radio_metros'       =>
            $ubicacion->radio_metros,
            'polygon_json'       =>
            $ubicacion->polygon_json,
            'direccion'          =>
            $ubicacion->direccion,
            'referencia'         =>
            $ubicacion->referencia,
            'activa'             =>
            (bool) $ubicacion->activa,
            'qr_modo'            =>
            $ubicacion->qr_modo,
            'qr_expira_segundos' =>
            (int) $ubicacion->qr_expira_segundos,
        ];

        $ubicacion->update([
            'nombre'             =>
            $data['nombre'],
            'descripcion'        =>
            $data['descripcion'] ?? null,
            'tipo_zona'          =>
            $data['tipo_zona'],
            'latitud'            =>
            $data['latitud'] ?? null,
            'longitud'           =>
            $data['longitud'] ?? null,
            'radio_metros'       =>
            $data['radio_metros'] ?? 100,
            'polygon_json'       =>
            $data['polygon_json'] ?? null,
            'direccion'          =>
            $data['direccion'] ?? null,
            'referencia'         =>
            $data['referencia'] ?? null,
            'activa'             =>
            $data['activa'] ?? 1,
            'qr_modo'            =>
            $data['qr_modo'] ?? 'ninguno',
            'qr_expira_segundos' =>
            $data['qr_expira_segundos'] ?? 60,
        ]);

        $ubicacion->refresh();

        $datosNuevos = [
            'nombre'             =>
            $ubicacion->nombre,
            'descripcion'        =>
            $ubicacion->descripcion,
            'tipo_zona'          =>
            $ubicacion->tipo_zona,
            'latitud'            =>
            $ubicacion->latitud,
            'longitud'           =>
            $ubicacion->longitud,
            'radio_metros'       =>
            $ubicacion->radio_metros,
            'polygon_json'       =>
            $ubicacion->polygon_json,
            'direccion'          =>
            $ubicacion->direccion,
            'referencia'         =>
            $ubicacion->referencia,
            'activa'             =>
            (bool) $ubicacion->activa,
            'qr_modo'            =>
            $ubicacion->qr_modo,
            'qr_expira_segundos' =>
            (int) $ubicacion->qr_expira_segundos,
        ];

        $this->auditoria->registrar([
            'id_portal'        =>
            (int) $administrator->id_portal,
            'id_cliente'       =>
            (int) $ubicacion->id_cliente,
            'actor_tipo'       => 'administrador',
            'actor_id'         => (int) $administrator->id,
            'actor_nombre'     =>
            $this->administratorName($administrator),
            'modulo'           => 'comunicacion360',
            'entidad_tipo'     => 'ubicacion_checada',
            'entidad_id'       => (int) $ubicacion->id,
            'accion'           => 'actualizar',
            'resultado'        => 'exitoso',
            'descripcion'      =>
            'Ubicación del checador actualizada.',
            'datos_anteriores' =>
            $datosAnteriores,
            'datos_nuevos'     =>
            $datosNuevos,
        ], $request);

        return response()->json([
            'ok'      => true,
            'message' => 'Ubicación actualizada correctamente.',
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $administrator = $this->administrator($request);

        $ubicacion = ChecadorUbicacion::query()
            ->where('id', $id)
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->first();

        if (! $ubicacion) {
            return response()->json([
                'ok'      => false,
                'message' => 'Ubicación no encontrada.',
            ], 404);
        }

        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $ubicacion->id_cliente]
        );

        $estadoAnterior = (bool) $ubicacion->activa;

        $ubicacion->update([
            'activa' => 0,
        ]);

        $this->auditoria->registrar([
            'id_portal'        =>
            (int) $administrator->id_portal,
            'id_cliente'       =>
            (int) $ubicacion->id_cliente,
            'actor_tipo'       => 'administrador',
            'actor_id'         => (int) $administrator->id,
            'actor_nombre'     =>
            $this->administratorName($administrator),
            'modulo'           => 'comunicacion360',
            'entidad_tipo'     => 'ubicacion_checada',
            'entidad_id'       => (int) $ubicacion->id,
            'accion'           => 'eliminar',
            'resultado'        => 'exitoso',
            'descripcion'      =>
            'Ubicación del checador desactivada.',
            'datos_anteriores' => [
                'activa' => $estadoAnterior,
            ],
            'datos_nuevos'     => [
                'activa' => false,
            ],
        ], $request);

        return response()->json([
            'ok'      => true,
            'message' => 'Ubicación desactivada correctamente.',
        ]);
    }
    private function administratorName(
        AdministradorAuth $administrator
    ): string {
        return trim(collect([
            $administrator->nombre ?? null,
            $administrator->paterno ?? null,
            $administrator->materno ?? null,
        ])->filter()->implode(' '));
    }
    private function administrator(
        Request $request
    ): AdministradorAuth {
        $administrator = $request->user();

        if (! $administrator instanceof AdministradorAuth) {
            throw new AuthorizationException(
                'Token administrativo no válido.'
            );
        }

        return $administrator;
    }
}
