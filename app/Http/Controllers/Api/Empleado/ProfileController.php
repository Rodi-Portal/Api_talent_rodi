<?php
namespace App\Http\Controllers\Api\Empleado;

use App\Http\Controllers\Controller;
use App\Models\Empleado;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    public function profile(Request $request)
    {
        $authEmpleado = $request->user();

        $empleado = Empleado::with([
            'domicilioEmpleado',
            'laborales',
            'informacionMedica',
            'camposExtra',
        ])
            ->where('id', $authEmpleado->id)
            ->first();

        if (! $empleado) {
            return response()->json([
                'message' => 'Empleado no encontrado',
            ], 404);
        }

        return response()->json($this->transformProfile($empleado));
    }

    private function transformProfile($empleado)
    {
        $fechaIngreso = $empleado->fecha_ingreso ?? $empleado->creacion;

        $antiguedad = null;

        if ($fechaIngreso) {
            $diff = Carbon::parse($fechaIngreso)->diff(now());

            $antiguedad = $diff->y . ' años ' . $diff->m . ' meses';
        }
        return [

            'general'   => [
                'nombreCompleto'   => trim(
                    $empleado->nombre . ' ' .
                    $empleado->paterno . ' ' .
                    $empleado->materno
                ),

                'correo'           => $empleado->correo,
                'telefono'         => $empleado->telefono,
                'fecha_nacimiento' => optional($empleado->fecha_nacimiento)->format('Y-m-d'),
                'curp'             => $empleado->curp,
                'rfc'              => $empleado->rfc,
                'nss'              => $empleado->nss,
            ],

            'domicilio' => [
                'pais'   => optional($empleado->domicilioEmpleado)->pais,
                'estado' => optional($empleado->domicilioEmpleado)->estado,
                'ciudad' => optional($empleado->domicilioEmpleado)->ciudad,
                'calle'  => optional($empleado->domicilioEmpleado)->calle,
                'cp'     => optional($empleado->domicilioEmpleado)->cp,
            ],

            'laboral'   => [
                'puesto'                 => $empleado->puesto,
                'departamento'           => $empleado->departamento,
                'fecha_ingreso'          => $fechaIngreso
                    ? Carbon::parse($fechaIngreso)->format('Y-m-d')
                    : null,

                'antiguedad'             => $antiguedad,
                'tipo_contrato'          =>
                optional($empleado->laborales)->tipo_contrato,

                'periodicidad_pago'      =>
                optional($empleado->laborales)->periodicidad_pago,

                'vacaciones_disponibles' =>
                optional($empleado->laborales)->vacaciones_disponibles,
            ],

            'medico'    => [
                'tipo_sangre'           =>
                optional($empleado->informacionMedica)->tipo_sangre,

                'peso'                  =>
                optional($empleado->informacionMedica)->peso,

                'alergias_medicamentos' =>
                optional($empleado->informacionMedica)->alergias_medicamentos,

                'contacto_emergencia'   =>
                optional($empleado->informacionMedica)->contacto_emergencia,
            ],

            /* 👇 NUEVO BLOQUE */

            'jefe'      => $this->getBoss($empleado),

            'extra'     => $empleado->camposExtra->map(function ($campo) {
                return [
                    'label' => $campo->nombre,
                    'value' => $campo->valor,
                ];
            })->values(),
        ];
    }

    private function getBoss($empleado)
    {
        $node = DB::connection('portal_main')->table('organigrama_nodes')
            ->where('empleado_id', $empleado->id)
            ->where('id_portal', $empleado->id_portal)
            ->where('id_cliente', $empleado->id_cliente)
            ->where('activo', 1)
            ->first();

        if (! $node || ! $node->parent_id) {
            return null;
        }

        $bossNode = DB::connection('portal_main')->table('organigrama_nodes')
            ->where('id', $node->parent_id)
            ->where('activo', 1)
            ->first();

        if (! $bossNode || ! $bossNode->empleado_id) {
            return null;
        }

        $boss = \App\Models\Empleado::find($bossNode->empleado_id);

        if (! $boss) {
            return null;
        }

        return [
            'nombre' => trim($boss->nombre . ' ' . $boss->paterno . ' ' . $boss->materno),
            'puesto' => $boss->puesto,
            'correo' => $boss->correo,
        ];
    }
}
