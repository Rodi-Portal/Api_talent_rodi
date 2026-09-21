<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Empleados\EmpleadoController;
use App\Models\ExamEmpleado;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Añade esta línea

class ApiGetCandidatosByCliente extends Controller
{
    /**
     * Display a listing of the resource filtered by id_cliente_talent.
     *
     * @param int $id_cliente_talent
     * @return \Illuminate\Http\Response
     */
    public function getByClienteTalent($id_cliente_talent)
    {
        if (! is_numeric($id_cliente_talent)) {
            return response()->json([
                'error' => 'Invalid id_cliente_talent',
            ], 400);
        }

        $rodiIntegrationUrl = rtrim(
            (string) config(
                'services.rodi_integration.base_url'
            ),
            '/'
        );

        $rodiIntegrationKey = (string) config(
            'integrations.rodi.document_key'
        );

        if (
            $rodiIntegrationUrl === ''
            || $rodiIntegrationKey === ''
        ) {
            Log::error(
                'Configuración de integración RODI incompleta',
                [
                    'id_cliente_talent' => $id_cliente_talent,
                ]
            );

            return response()->json([
                'error' => 'Integración RODI no configurada',
            ], 503);
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    'X-RODI-Integration-Key' =>
                        $rodiIntegrationKey,
                ])
                ->timeout(30)
                ->get(
                    $rodiIntegrationUrl .
                    '/clientes/' .
                    rawurlencode(
                        (string) $id_cliente_talent
                    ) .
                    '/candidatos'
                );

            return response(
                $response->body(),
                $response->status()
            )->header(
                'Content-Type',
                $response->header(
                    'Content-Type',
                    'application/json'
                )
            );
        } catch (\Throwable $e) {
            Log::error(
                'Error consultando candidatos en RODI',
                [
                    'id_cliente_talent' =>
                        $id_cliente_talent,
                    'error' => $e->getMessage(),
                ]
            );

            return response()->json([
                'error' => 'No fue posible consultar RODI',
            ], 502);
        }
    }
    public function sendCandidateToEmployee($id_candidato)
    {
        if (! is_numeric($id_candidato)) {
            return response()->json([
                'error' => 'Invalid id_candidato',
            ], 400);
        }

        $rodiIntegrationUrl = rtrim(
            (string) config(
                'services.rodi_integration.base_url'
            ),
            '/'
        );

        $rodiIntegrationKey = (string) config(
            'integrations.rodi.document_key'
        );

        if (
            $rodiIntegrationUrl === ''
            || $rodiIntegrationKey === ''
        ) {
            Log::error(
                'Configuración de integración RODI incompleta',
                [
                    'id_candidato' => $id_candidato,
                ]
            );

            return response()->json([
                'error' => 'Integración RODI no configurada',
            ], 503);
        }

        try {
            $rodiResponse = Http::acceptJson()
                ->withHeaders([
                    'X-RODI-Integration-Key' =>
                        $rodiIntegrationKey,
                ])
                ->timeout(30)
                ->get(
                    $rodiIntegrationUrl .
                    '/candidatos/' .
                    rawurlencode(
                        (string) $id_candidato
                    ) .
                    '/empleado-data'
                );
        } catch (\Throwable $e) {
            Log::error(
                'Error consultando candidato en RODI',
                [
                    'id_candidato' => $id_candidato,
                    'error'        => $e->getMessage(),
                ]
            );

            return response()->json([
                'error' => 'No fue posible consultar RODI',
            ], 502);
        }

        if ($rodiResponse->status() === 404) {
            return response()->json([
                'error' => 'Candidato no encontrado',
            ], 404);
        }

        if (! $rodiResponse->successful()) {
            Log::error(
                'RODI devolvió error al consultar candidato',
                [
                    'id_candidato' => $id_candidato,
                    'status'       => $rodiResponse->status(),
                ]
            );

            return response()->json([
                'error' => 'No fue posible consultar RODI',
            ], 502);
        }

        $payload = $rodiResponse->json();

        if (
            ! is_array($payload)
            || ($payload['success'] ?? false) !== true
            || ! is_array($payload['data'] ?? null)
        ) {
            Log::error(
                'Respuesta inválida de RODI para candidato',
                [
                    'id_candidato' => $id_candidato,
                ]
            );

            return response()->json([
                'error' => 'Respuesta inválida de RODI',
            ], 502);
        }

        $candidate = (object) $payload['data'];

        $fechaHoy = $this->getCurrentDateTime();

        $resultString = '';

        if (($candidate->socioeconomico ?? 0) == 1) {
            $resultString .= "Bgv \n ";
        }

        if (($candidate->tipo_antidoping ?? 0) > 0) {
            $resultString .= "Drug Test\n ";
        }

        if (($candidate->psicometrico ?? 0) == 1) {
            $resultString .= "Psicométric \n";
        }

        if (($candidate->medico ?? 0) == 1) {
            $resultString .= 'Medical Test ';
        }

        try {
            $validatedData = [
                'creacion'         => $fechaHoy,
                'edicion'          => $fechaHoy,
                'id_portal'        => $candidate->id_portal,
                'id_usuario'       => $candidate->id_usuario,
                'id_cliente'       => $candidate->id_cliente_talent,
                'id_empleado'      => $candidate->id,
                'correo'           => $candidate->correo,
                'fecha_nacimiento' => $candidate->fecha_nacimiento,
                'curp'             => $candidate->curp,
                'rfc'              => $candidate->rfc,
                'nss'              => $candidate->nss,
                'nombre'           => $candidate->nombre,
                'paterno'          => $candidate->paterno,
                'materno'          => $candidate->materno,
                'puesto'           => null,
                'telefono'         => $candidate->telefono,
                'domicilio_empleado' => [
                    'calle'   => $candidate->calle,
                    'num_ext' => $candidate->num_ext,
                    'num_int' => $candidate->num_int,
                    'colonia' => $candidate->colonia,
                    'ciudad'  => null,
                    'estado'  => null,
                    'pais'    => $candidate->pais,
                    'cp'      => $candidate->cp,
                ],
            ];

            $empleadoController = app(EmpleadoController::class);

            $response = $empleadoController->store(
                new Request($validatedData)
            );

            if ($response->getStatusCode() >= 400) {
                return $response;
            }

            if ($resultString !== '') {
                $empleadoData = $response->getData()->data ?? null;

                if ($empleadoData) {
                    $empleadoId = $empleadoData->id;

                    $examEmpleado = new ExamEmpleado([
                        'creacion'        => $fechaHoy,
                        'edicion'         => $fechaHoy,
                        'employee_id'     => $empleadoId,
                        'name'            => $resultString,
                        'id_opcion'       => null,
                        'descripcion'     => null,
                        'expiry_date'     => null,
                        'expiry_reminder' => null,
                        'id_candidato'    => $candidate->id ?? null,
                    ]);

                    $examEmpleado->save();
                } else {
                    Log::error(
                        'No se pudo encontrar el empleado en la respuesta',
                        [
                            'id_candidato' => $id_candidato,
                        ]
                    );
                }
            }

            return response()->json([
                'success'   => 'Candidato procesado correctamente',
                'candidato' => $candidate,
            ]);
        } catch (\Throwable $e) {
            Log::error(
                'Error procesando candidato como empleado',
                [
                    'id_candidato' => $id_candidato,
                    'error'        => $e->getMessage(),
                ]
            );

            return response()->json([
                'error'   => 'Ocurrió un error al procesar el candidato',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function getCurrentDateTime()
    {
        // Obtiene la fecha y hora actuales en la zona horaria de México
        $currentDateTime = Carbon::now('America/Mexico_City');

        // Formatea la fecha y hora
        return $currentDateTime->format('Y-m-d H:i:s');
    }

}
